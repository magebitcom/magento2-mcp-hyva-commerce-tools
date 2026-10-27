<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaForm;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Delivery\DeliveryAudit;
use PHPUnit\Framework\TestCase;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * hyva_form.delivery.audit without a form audits only the forms of the caller's store views.
 */
class DeliveryAuditScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testARestrictedRoleAuditsOnlyItsForms(): void
    {
        $payload = $this->payload($this->tool([1])->execute([]));

        $this->assertSame([9, 7], array_column($this->forms($payload), 'id'));
        $this->assertSame(2, $payload['forms_total']);
        $this->assertSame(1, $payload['forms_hidden_by_permissions']);
        $this->assertFalse($payload['truncated']);
    }

    public function testAnUnrestrictedCallerAuditsEveryForm(): void
    {
        $payload = $this->payload($this->tool(null)->execute([]));

        $this->assertSame([9, 8, 7], array_column($this->forms($payload), 'id'));
        $this->assertSame(3, $payload['forms_total']);
        $this->assertSame(0, $payload['forms_hidden_by_permissions']);
    }

    public function testARestrictedRoleAuditsItsFormsPastTheFirstPage(): void
    {
        // 250 forms, newest first: even ids on store view 1 (readable), odd ids on store view 2.
        $all = [];
        for ($id = 250; $id >= 1; $id--) {
            $all[] = $this->form($id, [$id % 2 === 0 ? 1 : 2]);
        }
        $gateway = $this->createMock(FormGatewayInterface::class);
        $gateway->expects($this->exactly(2))->method('getList')->willReturnCallback(
            static fn (?string $search, ?int $storeId, int $page, int $pageSize): array => [
                'total' => count($all),
                'items' => array_slice($all, ($page - 1) * $pageSize, $pageSize),
            ]
        );

        $payload = $this->payload($this->tool([1], $gateway)->execute([]));

        $ids = array_column($this->forms($payload), 'id');
        $this->assertCount(125, $ids);
        $this->assertContains(2, $ids, 'a form of the second page');
        $this->assertNotContains(249, $ids);
        $this->assertSame(125, $payload['forms_total']);
        $this->assertSame(125, $payload['forms_hidden_by_permissions']);
        $this->assertFalse($payload['truncated']);
    }

    /**
     * Form row.
     *
     * @param int $id
     * @param array $storeIds
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return array<string, mixed>
     */
    private function form(int $id, array $storeIds): array
    {
        return [
            'id' => $id, 'identifier' => 'f' . $id, 'title' => 'F', 'is_active' => true, 'store_ids' => $storeIds,
            'recipient_to' => 'a@example.com', 'recipient_cc' => '', 'recipient_bcc' => '', 'subject_template' => '',
            'webhook_url' => '', 'webhook_format' => 'json', 'is_captcha_enabled' => true, 'preview_url_key' => '',
            'has_draft' => false, 'has_published' => true, 'updated_at' => '',
        ];
    }

    /**
     * Tool over forms 9 (store view 1), 8 (store view 2) and 7 (all store views), or over the given gateway.
     *
     * @param array|null $allowedStoreIds
     * @param FormGatewayInterface|null $gateway
     * @return DeliveryAudit
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function tool(?array $allowedStoreIds, ?FormGatewayInterface $gateway = null): DeliveryAudit
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        if ($gateway === null) {
            $gateway = $this->createMock(FormGatewayInterface::class);
            $gateway->method('getList')->willReturn([
                'total' => 3,
                'items' => [$this->form(9, [1]), $this->form(8, [2]), $this->form(7, [0])],
            ]);
        }
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getDocuments')->willReturn(['draft' => null, 'published' => null]);
        return new DeliveryAudit(
            $features,
            $gateway,
            $contentService,
            new FormRules($this->createMock(ComponentTree::class)),
            $this->storeScope($allowedStoreIds)
        );
    }

    /**
     * Audited forms of a payload.
     *
     * @param array $payload
     * @return array
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return list<array<string, mixed>>
     */
    private function forms(array $payload): array
    {
        $forms = $payload['forms'];
        $this->assertIsArray($forms);
        return array_values(array_map(
            static fn (array $form): array => Cast::stringKeyed($form),
            array_filter($forms, 'is_array')
        ));
    }

    /**
     * Decoded JSON payload.
     *
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
