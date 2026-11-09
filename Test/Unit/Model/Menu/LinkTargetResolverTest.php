<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Menu;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Menu\LinkTargetResolver;
use Magebit\McpHyvaCommerceTools\Model\Merchandising\CategoryPositions;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

/**
 * CMS page link checks read only the linked identifiers, never the page content. T-182: product links resolve
 * out-of-stock and not yet indexed products too.
 */
class LinkTargetResolverTest extends TestCase
{
    public function testCmsPagesAreLookedUpByIdentifierWithoutContent(): void
    {
        $index = $this->createMock(ContentIndex::class);
        $index->expects($this->never())->method('getRows');
        $index->expects($this->once())->method('getByIdentifiers')->with('cms_page', ['about', 'gone'])->willReturn([
            ['id' => 1, 'identifier' => 'about', 'is_active' => false, 'store_ids' => [2]],
            ['id' => 4, 'identifier' => 'about', 'is_active' => true, 'store_ids' => [1]],
            ['id' => 5, 'identifier' => 'about', 'is_active' => false, 'store_ids' => [3]],
        ]);
        $resolver = new LinkTargetResolver(
            $this->createMock(CategoryCollectionFactory::class),
            $this->createMock(ProductCollectionFactory::class),
            $index,
            $this->createMock(ResourceConnection::class)
        );

        $this->assertSame([
            'about' => ['exists' => true, 'active' => true, 'store_ids' => [1]],
            'gone' => ['exists' => false, 'active' => false, 'store_ids' => []],
        ], $resolver->getCmsPages(['about', 'gone']));
    }

    public function testOutOfStockAndUnindexedProductsAreFoundWithoutTheStorefrontStockFilter(): void
    {
        $calls = [];
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setFlag')->willReturnCallback(
            function (string $flag, mixed $value) use (&$calls, $collection): ProductCollection {
                $calls[] = 'setFlag:' . $flag . '=' . var_export($value, true);
                return $collection;
            }
        );
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->with('sku', ['in' => ['oos', 'off', 'gone']])->willReturnSelf();
        $collection->method('getItems')->willReturnCallback(function () use (&$calls): array {
            $calls[] = 'getItems';
            return [
                new DataObject(['sku' => 'oos', 'status' => 1, 'name' => 'Out of stock']),
                new DataObject(['sku' => 'off', 'status' => 2, 'name' => 'Disabled']),
            ];
        });
        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $resolver = new LinkTargetResolver(
            $this->createMock(CategoryCollectionFactory::class),
            $factory,
            $this->createMock(ContentIndex::class),
            $this->createMock(ResourceConnection::class)
        );

        $this->assertSame([
            'oos' => ['exists' => true, 'enabled' => true, 'name' => 'Out of stock'],
            'off' => ['exists' => true, 'enabled' => false, 'name' => 'Disabled'],
            'gone' => ['exists' => false, 'enabled' => false, 'name' => ''],
        ], $resolver->getProducts(['oos', 'off', 'gone'], 1));
        // The flag must be set before the collection loads, where the frontend stock plugin runs.
        $this->assertSame(['setFlag:' . CategoryPositions::STOCK_FILTER_FLAG . '=true', 'getItems'], $calls);
    }
}
