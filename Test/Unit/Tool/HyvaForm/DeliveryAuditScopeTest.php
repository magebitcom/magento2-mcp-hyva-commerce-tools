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
     * Tool over forms 9 (store view 1), 8 (store view 2) and 7 (all store views).
     *
     * @param array|null $allowedStoreIds
     * @return DeliveryAudit
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function tool(?array $allowedStoreIds): DeliveryAudit
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gateway = $this->createMock(FormGatewayInterface::class);
        $gateway->method('getList')->willReturn([
            'total' => 3,
            'items' => [$this->form(9, [1]), $this->form(8, [2]), $this->form(7, [0])],
        ]);
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
        return array_values(array_filter($forms, 'is_array'));
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
