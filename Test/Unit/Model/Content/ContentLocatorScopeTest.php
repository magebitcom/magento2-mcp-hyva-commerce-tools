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
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\Collection as PageCollection;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * The shared locator applies the caller's website/store view restriction (finding: scope not enforced on /mcp).
 */
class ContentLocatorScopeTest extends TestCase
{
    use StoreScopeStub;

    /**
     * Pages by id: 1 = store view 1, 2 = store view 2, 3 = all store views, 4 = store views 1 and 2.
     *
     * @var array<int, Page>
     */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->pages = [
            1 => $this->page(1, [1]),
            2 => $this->page(2, [2]),
            3 => $this->page(3, [0]),
            4 => $this->page(4, [1, 2]),
        ];
    }

    public function testAnUnrestrictedCallerReadsAndWritesEverything(): void
    {
        $locator = $this->locator(null, []);
        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame($id, $locator->locate(ContentLocator::TYPE_PAGE, ['id' => $id])['id']);
            $this->assertSame($id, $locator->locateForWrite(ContentLocator::TYPE_PAGE, ['id' => $id])['id']);
        }
    }

    public function testARestrictedCallerCannotReadAnotherWebsitesPage(): void
    {
        $locator = $this->locator([1], []);
        $this->assertSame(1, $locator->locate(ContentLocator::TYPE_PAGE, ['id' => 1])['id']);
        $this->assertSame(3, $locator->locate(ContentLocator::TYPE_PAGE, ['id' => 3])['id']);
        $this->assertSame(4, $locator->locate(ContentLocator::TYPE_PAGE, ['id' => 4])['id']);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('CMS page #2');
        $locator->locate(ContentLocator::TYPE_PAGE, ['id' => 2]);
    }

    public function testARestrictedCallerCannotChangeAllStoreViewsContent(): void
    {
        $locator = $this->locator([1], []);
        $this->assertSame(1, $locator->locateForWrite(ContentLocator::TYPE_PAGE, ['id' => 1])['id']);
        $this->expectException(AuthorizationException::class);
        $locator->locateForWrite(ContentLocator::TYPE_PAGE, ['id' => 3]);
    }

    public function testARestrictedCallerCannotChangeAPageSharedWithAForeignStoreView(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->locator([1], [])->locateForWrite(ContentLocator::TYPE_PAGE, ['id' => 4]);
    }

    public function testAnAmbiguousIdentifierResolvesToTheOnlyVisiblePage(): void
    {
        $this->assertSame(
            2,
            $this->locator([2], [1, 2])->locate(ContentLocator::TYPE_PAGE, ['identifier' => 'sale'])['id']
        );
    }

    public function testPartitionKeepsWritableTargetsAndHidesInvisibleOnes(): void
    {
        $targets = [
            ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 1, 'identifier' => 'a'],
            ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 2, 'identifier' => 'b'],
            ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 3, 'identifier' => 'c'],
        ];
        $this->assertSame(
            ['writable' => [$targets[0]], 'read_only' => [$targets[2]]],
            $this->locator([1], [])->partitionByScope($targets)
        );
        $this->assertSame(
            ['writable' => $targets, 'read_only' => []],
            $this->locator(null, [])->partitionByScope($targets)
        );
    }

    /**
     * Locator over the pages; the identifier lookup finds $identifierIds.
     *
     * @param array|null $allowedStoreIds
     * @param array $identifierIds
     * @return ContentLocator
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<int> $identifierIds
     */
    private function locator(?array $allowedStoreIds, array $identifierIds): ContentLocator
    {
        $collection = $this->createMock(PageCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getAllIds')->willReturn($identifierIds);
        $collectionFactory = $this->createMock(PageCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(fn (int $id): Page => $this->pages[$id]);

        return new ContentLocator(
            $repository,
            $this->createMock(BlockRepositoryInterface::class),
            $collectionFactory,
            $this->createMock(BlockCollectionFactory::class),
            $this->createMock(MenuGatewayInterface::class),
            $this->createMock(FeatureCheckerInterface::class),
            $this->storeScope($allowedStoreIds)
        );
    }

    /**
     * Page.
     *
     * @param int $id
     * @param array $storeIds
     * @return Page
     * @phpstan-param list<int> $storeIds
     */
    private function page(int $id, array $storeIds): Page
    {
        $page = $this->getMockBuilder(Page::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $page->method('getId')->willReturn($id);
        $page->setData(['identifier' => 'sale', 'title' => 'Sale', 'is_active' => true, 'store_id' => $storeIds]);
        return $page;
    }
}
