<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\ResourceModel\Block\Collection as BlockCollection;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ContentLocatorTest extends TestCase
{
    /**
     * Disabled pages must stay reachable by identifier + store_id (drafts are prepared on inactive pages).
     */
    public function testStoreLookupFindsDisabledStoreSpecificPage(): void
    {
        $locator = $this->locator([3, 7], [3 => $this->page(3, [0], true), 7 => $this->page(7, [2], false)]);
        $result = $locator->locate(ContentLocator::TYPE_PAGE, ['identifier' => 'sale', 'store_id' => 2]);

        $this->assertSame(7, $result['id']);
        $this->assertFalse($result['is_active']);
    }

    public function testStoreLookupFallsBackToAllStoreViewsPage(): void
    {
        $locator = $this->locator([3], [3 => $this->page(3, [0], false)]);
        $result = $locator->locate(ContentLocator::TYPE_PAGE, ['identifier' => 'sale', 'store_id' => 2]);

        $this->assertSame(3, $result['id']);
    }

    public function testStoreLookupFindsDisabledStoreSpecificBlock(): void
    {
        $blocks = [4 => $this->block(4, [0], true), 9 => $this->block(9, [2], false)];
        $collection = $this->createMock(BlockCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addStoreFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturn([4, 9]);
        $collectionFactory = $this->createMock(BlockCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $repository = $this->createMock(BlockRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(static fn (string $id): Block => $blocks[(int) $id]);
        $locator = new ContentLocator(
            $this->createMock(PageRepositoryInterface::class),
            $repository,
            $this->createMock(PageCollectionFactory::class),
            $collectionFactory,
            $this->createMock(MenuGatewayInterface::class),
            $this->createMock(FeatureCheckerInterface::class),
            $this->createMock(StoreScopeGuard::class)
        );

        $result = $locator->locate(ContentLocator::TYPE_BLOCK, ['identifier' => 'promo', 'store_id' => 2]);
        $this->assertSame(9, $result['id']);
        $this->assertFalse($result['is_active']);
    }

    public function testAmbiguousIdentifierWithoutStoreIsRejected(): void
    {
        $locator = $this->locator([3, 7], []);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Several CMS pages use identifier "sale" \(ids 3, 7\)/');
        $locator->locate(ContentLocator::TYPE_PAGE, ['identifier' => 'sale']);
    }

    /**
     * Locator whose page collection returns $ids and whose repository serves $pages.
     *
     * @param array $ids
     * @param array $pages
     * @return ContentLocator
     * @phpstan-param list<int> $ids
     * @phpstan-param array<int, Page> $pages
     */
    private function locator(array $ids, array $pages): ContentLocator
    {
        $collection = $this->createMock(PageCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addStoreFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturn($ids);
        $collectionFactory = $this->createMock(PageCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(static fn (int $id): Page => $pages[$id]);

        return new ContentLocator(
            $repository,
            $this->createMock(BlockRepositoryInterface::class),
            $collectionFactory,
            $this->createMock(BlockCollectionFactory::class),
            $this->createMock(MenuGatewayInterface::class),
            $this->createMock(FeatureCheckerInterface::class),
            $this->createMock(StoreScopeGuard::class)
        );
    }

    /**
     * Page.
     *
     * @param int $id
     * @param array $storeIds
     * @param bool $active
     * @return Page
     * @phpstan-param list<int> $storeIds
     */
    private function page(int $id, array $storeIds, bool $active): Page
    {
        $page = $this->getMockBuilder(Page::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $page->method('getId')->willReturn($id);
        $page->setData(['identifier' => 'sale', 'title' => 'Sale', 'is_active' => $active, 'store_id' => $storeIds]);
        return $page;
    }

    /**
     * Block.
     *
     * @param int $id
     * @param array $storeIds
     * @param bool $active
     * @return Block
     * @phpstan-param list<int> $storeIds
     */
    private function block(int $id, array $storeIds, bool $active): Block
    {
        $block = $this->getMockBuilder(Block::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $block->method('getId')->willReturn($id);
        $block->setData(['identifier' => 'promo', 'title' => 'Promo', 'is_active' => $active, 'store_id' => $storeIds]);
        return $block;
    }
}
