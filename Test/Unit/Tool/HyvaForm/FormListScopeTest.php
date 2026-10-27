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
use Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form\FormList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * hyva_form.form.list hides forms of other store views and keeps total and pages right.
 */
class FormListScopeTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @var FormGatewayInterface&MockObject
     */
    private FormGatewayInterface $gateway;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(FormGatewayInterface::class);
    }

    public function testARestrictedRoleGetsItsFormsWithTheirOwnTotal(): void
    {
        $this->gateway->expects($this->once())->method('getList')->with(null, null, 1, 200)->willReturn([
            'total' => 3,
            'items' => [$this->form(9, [1]), $this->form(8, [2]), $this->form(7, [0])],
        ]);

        $payload = $this->payload($this->tool([1])->execute(['page' => 2, 'page_size' => 1]));

        $this->assertSame(2, $payload['total']);
        $items = $payload['items'];
        $this->assertIsArray($items);
        $this->assertSame([7], array_column($items, 'id'));
    }

    public function testAnUnrestrictedCallerIsPaginatedByTheGateway(): void
    {
        $this->gateway->expects($this->once())->method('getList')->with('x', 1, 2, 10)
            ->willReturn(['total' => 11, 'items' => [$this->form(1, [2])]]);

        $payload = $this->payload($this->tool(null)->execute(
            ['search' => 'x', 'store_id' => 1, 'page' => 2, 'page_size' => 10]
        ));

        $this->assertSame(11, $payload['total']);
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
            'recipient_to' => '', 'recipient_cc' => '', 'recipient_bcc' => '', 'subject_template' => '',
            'webhook_url' => '', 'webhook_format' => 'json', 'is_captcha_enabled' => false, 'preview_url_key' => '',
            'has_draft' => false, 'has_published' => true, 'updated_at' => '',
        ];
    }

    /**
     * Tool for a caller restricted to the store views (null = unrestricted).
     *
     * @param array|null $allowedStoreIds
     * @return FormList
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function tool(?array $allowedStoreIds): FormList
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return new FormList(
            $features,
            $this->gateway,
            $this->createMock(ContentService::class),
            new FormRules($this->createMock(ComponentTree::class)),
            $this->storeScope($allowedStoreIds)
        );
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
