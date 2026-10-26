<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\LinkedProducts;

use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Hyva\LinkedProducts\Model\Attribute\Source\ListSelectAttributes;
use Hyva\LinkedProducts\Model\Duplicate\DefaultScopeReachabilityReader;
use Hyva\LinkedProducts\Model\Duplicate\DuplicateCombinationDetector;
use Hyva\LinkedProducts\Model\LinkedGroupSaver;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup as LinkedGroupResource;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup\Attribute as AttributeResource;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup\CollectionFactory;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup\Product as ProductResource;
use Magebit\McpHyvaCommerceTools\Model\Adapter\LinkedProducts\LinkedProductsGateway;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContextFactory;
use PHPUnit\Framework\TestCase;

class LinkedProductsGatewayTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * Products 1-4 exist; 1 and 2 are in group 7, 3 is in group 9; products 1 and 2 share the color option.
     *
     * @var LinkedProductsGateway
     */
    private LinkedProductsGateway $gateway;

    protected function setUp(): void
    {
        $groups = $this->createMock(LinkedGroupResource::class);
        $groups->method('findGroupIdsByName')->willReturnCallback(
            static fn (string $name): array => strcasecmp($name, 'Tees') === 0 ? [7] : []
        );
        $attributes = $this->createMock(AttributeResource::class);
        $attributes->method('findProductAttributeMetaByCodes')->willReturn([
            'color' => ['attribute_id' => 93, 'is_linkable' => true],
            'sku' => ['attribute_id' => 74, 'is_linkable' => false],
        ]);
        $products = $this->createMock(ProductResource::class);
        $products->method('findExistingEntityIds')->willReturnCallback(
            static fn (array $ids): array => array_values(array_intersect($ids, [1, 2, 3, 4]))
        );
        $products->method('findGroupIdsByProductIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key([1 => 7, 2 => 7, 3 => 9], array_flip($ids))
        );
        $products->method('findSkusByEntityIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key(
                [1 => 'TEE-R', 2 => 'TEE-R2', 3 => 'MUG', 4 => 'TEE-B'],
                array_flip($ids)
            )
        );
        $reachability = $this->createMock(DefaultScopeReachabilityReader::class);
        $reachability->method('read')->willReturnCallback(static function (array $ids): array {
            $rows = [];
            foreach ($ids as $id) {
                $rows[$id] = [
                    'ids' => ['color' => $id === 4 ? '51' : '50'],
                    'labels' => ['color' => $id === 4 ? 'Blue' : 'Red'],
                    'eligible' => $id !== 4,
                    'ineligibleReason' => $id === 4 ? 'not_visible' : null,
                ];
            }
            return $rows;
        });
        $this->gateway = new LinkedProductsGateway(
            $this->optionalServices([
                CollectionFactory::class => $this->createMock(CollectionFactory::class),
                LinkedGroupResource::class => $groups,
                AttributeResource::class => $attributes,
                ProductResource::class => $products,
                ListSelectAttributes::class => $this->createMock(ListSelectAttributes::class),
                DefaultScopeReachabilityReader::class => $reachability,
                DuplicateCombinationDetector::class => new DuplicateCombinationDetector(),
                LinkedGroupSaver::class => $this->createMock(LinkedGroupSaver::class),
            ]),
            $this->createMock(CacheContextFactory::class),
            $this->createMock(EventManager::class)
        );
    }

    public function testEditingAGroupDoesNotFlagItsOwnNameOrMembers(): void
    {
        $codes = $this->codes($this->gateway->check(7, 'Tees', ['color'], [1, 2, 4])['issues']);
        $this->assertNotContains('name_taken', $codes);
        $this->assertNotContains('in_other_group', $codes);
        $this->assertContains('member_not_on_storefront', $codes, 'product 4 is not visible');
        $this->assertContains('same_link_values', $codes, 'products 1 and 2 share the color option');
    }

    public function testNewGroupErrors(): void
    {
        $codes = $this->codes($this->gateway->check(null, 'tees', ['color', 'sku'], [3, 99])['issues']);
        $this->assertContains('name_taken', $codes, 'names compare case-insensitively');
        $this->assertContains('attribute_not_linkable', $codes);
        $this->assertContains('product_missing', $codes);
        $this->assertContains('in_other_group', $codes);
        $this->assertNotContains('too_few_members', $codes, 'two distinct ids were submitted');
        $this->assertContains(
            'too_few_members',
            $this->codes($this->gateway->check(null, 'New', ['color'], [4, 4])['issues'])
        );
        $this->assertContains(
            'invalid_name',
            $this->codes($this->gateway->check(null, '  ', ['color'], [1, 4])['issues'])
        );
    }

    /**
     * @param list<array{severity: string, code: string, message: string}> $issues
     * @return list<string>
     */
    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }
}
