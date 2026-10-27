<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Merchandising;

use Magebit\McpHyvaCommerceTools\Model\Merchandising\CategoryPositions;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryPositionsTest extends TestCase
{
    /**
     * @var CategoryResource&MockObject
     */
    private CategoryResource $resource;

    /**
     * @var CategoryPositions
     */
    private CategoryPositions $positions;

    protected function setUp(): void
    {
        $category = $this->createMock(Category::class);
        $repository = $this->createMock(CategoryRepositoryInterface::class);
        $repository->method('get')->willReturn($category);
        $this->resource = $this->createMock(CategoryResource::class);
        // Stored order: 30 (pos 0), 10 and 20 tied at 5 (id order), 40 (pos 9).
        $this->resource->method('getProductsPosition')->willReturn([10 => '5', 20 => '5', 30 => '0', 40 => '9']);
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn(array_map(
            static fn (int $id): DataObject => new DataObject(['entity_id' => $id, 'sku' => 'SKU-' . $id,
                'status' => 1, 'visibility' => 4]),
            [10, 20, 30, 40]
        ));
        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $indexer = $this->createMock(IndexerInterface::class);
        $indexers = $this->createMock(IndexerRegistry::class);
        $indexers->method('get')->willReturn($indexer);
        $this->positions = new CategoryPositions(
            $repository,
            $this->resource,
            $factory,
            $indexers,
            $this->createMock(ModuleManager::class)
        );
    }

    public function testTopSkusMoveFirstAndTheRestKeepTheirOrder(): void
    {
        $plan = $this->positions->plan(6, ['sku-40', 'SKU-20']);
        $this->assertSame(['SKU-40', 'SKU-20', 'SKU-30', 'SKU-10'], array_column($plan['after'], 'sku'));
        $this->assertSame([40 => 0, 20 => 1, 30 => 2, 10 => 3], $plan['positions']);
        $this->assertSame(['SKU-30', 'SKU-10', 'SKU-20', 'SKU-40'], array_column($plan['before'], 'sku'));
    }

    public function testGetLoadsOnlyTheFirstProductsButCountsAll(): void
    {
        $result = $this->positions->get(6, 2);
        $this->assertSame(4, $result['category']['product_count']);
        $this->assertSame(['SKU-30', 'SKU-10'], array_column($result['products'], 'sku'));
    }

    public function testOnlyProductsOfTheCategoryCanBeMoved(): void
    {
        $this->expectException(LocalizedException::class);
        $this->positions->plan(6, ['SKU-10', 'OTHER']);
    }

    public function testSaveRefusesWhenMembershipChangedSincePreview(): void
    {
        $this->resource->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);
        $this->positions->save(6, [10 => 0, 20 => 1, 30 => 2]);
    }
}
