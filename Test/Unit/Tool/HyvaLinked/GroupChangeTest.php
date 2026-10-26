<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaLinked;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupSave;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupValidate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupChangeTest extends TestCase
{
    /**
     * @var LinkedProductsGatewayInterface&MockObject
     */
    private LinkedProductsGatewayInterface $gateway;

    /**
     * @var FeatureCheckerInterface&MockObject
     */
    private FeatureCheckerInterface $features;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(LinkedProductsGatewayInterface::class);
        $this->gateway->method('get')->willReturn([
            'id' => 5, 'name' => 'Tees', 'attributes' => [['code' => 'color', 'label' => 'Color']],
            'member_count' => 2, 'skus' => ['A', 'B'], 'created_at' => '', 'updated_at' => '',
        ]);
        $this->gateway->method('productIdsBySkus')->willReturnCallback(
            static fn (array $skus): array => array_intersect_key(
                ['A' => 1, 'B' => 2, 'C' => 3],
                array_flip(array_map('strtoupper', $skus))
            )
        );
        $this->features = $this->createMock(FeatureCheckerInterface::class);
        $this->features->method('isAvailable')->willReturn(true);
    }

    public function testAddAndRemoveEditTheCurrentMembers(): void
    {
        $this->gateway->expects($this->once())->method('check')
            ->with(5, 'Tees', ['color'], [1, 3])
            ->willReturn(['issues' => [], 'members' => []]);
        $payload = $this->payload((new GroupValidate($this->features, $this->gateway))->execute([
            'id' => 5, 'add_skus' => ['C'], 'remove_skus' => ['B'],
        ]));
        $this->assertTrue($payload['valid']);
        $this->assertSame(['A', 'C'], $payload['skus']);
    }

    public function testSkusMatchCaseInsensitively(): void
    {
        $checked = [];
        $this->gateway->method('check')->willReturnCallback(
            static function (?int $id, string $name, array $codes, array $ids) use (&$checked): array {
                $checked[] = $ids;
                return ['issues' => [], 'members' => []];
            }
        );
        $payload = $this->payload((new GroupValidate($this->features, $this->gateway))->execute([
            'id' => 5, 'add_skus' => ['c'], 'remove_skus' => ['b', 'C'],
        ]));
        $this->assertTrue($payload['valid'], 'no unknown_sku for a different spelling');
        $this->assertSame(['A'], $payload['skus'], 'remove_skus matches B and the added c regardless of case');

        $payload = $this->payload((new GroupValidate($this->features, $this->gateway))->execute([
            'name' => 'New', 'skus' => ['a', 'b'],
        ]));
        $this->assertSame(['A', 'B'], $payload['skus'], 'stored spelling is kept');
        $this->assertSame([[1], [1, 2]], $checked);
    }

    public function testUnknownSkuIsAnErrorAndNothingIsSaved(): void
    {
        $this->gateway->method('check')->willReturn(['issues' => [], 'members' => []]);
        $this->gateway->expects($this->never())->method('save');
        $result = (new GroupSave($this->features, $this->gateway))->execute([
            'name' => 'New', 'skus' => ['A', 'ZZ'], 'confirm' => true,
        ]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('unknown_sku', json_encode($this->payload($result)) ?: '');
    }

    public function testSaveWithoutConfirmIsAPreview(): void
    {
        $this->gateway->method('check')->willReturn(['issues' => [], 'members' => []]);
        $this->gateway->expects($this->never())->method('save');
        $payload = $this->payload((new GroupSave($this->features, $this->gateway))->execute([
            'name' => 'New', 'skus' => ['A', 'B'],
        ]));
        $this->assertTrue($payload['confirmation_required']);
        $this->assertSame('create_linked_group', $payload['action']);
    }

    /**
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload, $text);
        return $payload;
    }
}
