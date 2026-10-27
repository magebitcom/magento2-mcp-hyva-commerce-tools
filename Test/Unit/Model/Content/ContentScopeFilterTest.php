<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Security\CatalogScopeReader;
use PHPUnit\Framework\TestCase;

/**
 * Usage lists, audits and widgets hide content of other websites' store views from a restricted caller (finding:
 * unscoped image/audit lists), and products and categories of other websites as Hyvä's catalog guards do.
 */
class ContentScopeFilterTest extends TestCase
{
    use ContentScopeStub;

    /**
     * Store views: page 1 = 1, page 2 = 2, page 3 = all; block 4 = 2; menu 5 = 1 and 2.
     */
    private const STORE_IDS = [
        ContentLocator::TYPE_PAGE => [1 => [1], 2 => [2], 3 => [0]],
        ContentLocator::TYPE_BLOCK => [4 => [2]],
        ContentLocator::TYPE_MENU => [5 => [1, 2]],
    ];

    /**
     * Products: 2 = website 1, 6 = website 2, 7 = no website, 8 = websites 1 and 2 (9 does not exist).
     * Categories (root of store view 1 = 11, of store view 2 = 12): 4 and 11 in tree 11, 5 and 12 in tree 12,
     * 1 = the tree root (99 does not exist).
     */
    private const CATALOG = [
        'product' => [2 => [1], 6 => [2], 7 => [], 8 => [1, 2]],
        'category' => [4 => '1/11/4', 11 => '1/11', 5 => '1/12/5', 12 => '1/12', 1 => '1'],
    ];

    public function testAnUnrestrictedCallerGetsEverythingWithoutALookup(): void
    {
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->expects($this->never())->method('getStoreIds');
        $catalogReader = $this->createMock(CatalogScopeReader::class);
        $catalogReader->expects($this->never())->method('productWebsiteIds');
        $catalogReader->expects($this->never())->method('categoryPaths');
        $filter = $this->contentScope(null, [], $contentIndex, [], $catalogReader);
        $items = $this->items();
        $usages = [3 => ['hyva_and_catalog' => $items, 'magento_tracked' => [['entity_type' => 'catalog_product',
            'entity_id' => '6']]]];

        $this->assertFalse($filter->isRestricted());
        $this->assertSame(['visible' => $items, 'hidden' => 0], $filter->filter($items));
        $this->assertSame(
            ['visible' => ['x' => $items], 'hidden' => ['x' => 0]],
            $filter->filterGrouped(['x' => $items])
        );
        $rows = [['store_ids' => [2]]];
        $this->assertSame(['visible' => $rows, 'hidden' => 0], $filter->filterByStoreIds($rows));
        $report = ['total' => 3, 'used' => true, 'hyva' => $items];
        $this->assertSame($report, $filter->filterUsageReport($report, ['hyva']));
        $this->assertSame(['a' => 1], $filter->withHidden(['a' => 1], 'hidden', 4));
        $this->assertSame(['visible' => $usages, 'hidden' => [3 => 0]], $filter->filterAssetUsages($usages));
    }

    public function testARestrictedCallerSeesOnlyContentOfItsStoreViewsAndEverythingElse(): void
    {
        $filter = $this->contentScope([1], self::STORE_IDS, null, self::CATALOG);

        $result = $filter->filter($this->items());

        // Page 2, block 4 and the deleted page 9 are hidden; product 2 and category 4 are in website 1.
        $this->assertSame(
            [['cms_page', 1], ['cms_page', 3], ['menu', 5], ['product', 2], ['category', 4]],
            array_map(static fn (array $i): array => [$i['entity_type'], $i['id']], $result['visible'])
        );
        $this->assertSame(3, $result['hidden']);
        $this->assertSame(['a' => 1, 'hidden' => 3], $filter->withHidden(['a' => 1], 'hidden', 3));
    }

    public function testListsAreCheckedWithOneStoreViewLookupPerType(): void
    {
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->expects($this->exactly(3))->method('getStoreIds')->willReturnCallback(
            static fn (string $type, array $ids): array => array_intersect_key(
                self::STORE_IDS[$type] ?? [],
                array_flip($ids)
            )
        );
        $filter = $this->contentScope([1], [], $contentIndex, self::CATALOG);

        $result = $filter->filterGrouped(['a.jpg' => $this->items(), 'b.jpg' => $this->items()]);
        // Already looked up: no further query.
        $filter->filter($this->items());

        $this->assertSame(['a.jpg' => 3, 'b.jpg' => 3], $result['hidden']);
        $this->assertCount(5, $result['visible']['b.jpg']);
    }

    public function testIdsMayComeUnderAnotherKeyAsStrings(): void
    {
        $tracked = [
            ['entity_type' => 'cms_page', 'entity_id' => '1', 'field' => 'content'],
            ['entity_type' => 'cms_block', 'entity_id' => '4', 'field' => 'content'],
            ['entity_type' => 'catalog_product', 'entity_id' => '4', 'field' => 'description'],
        ];

        $result = $this->contentScope([1], self::STORE_IDS, null, ['product' => [4 => [1]]])
            ->filter($tracked, 'entity_type', 'entity_id');

        $this->assertSame([$tracked[0], $tracked[2]], $result['visible']);
        $this->assertSame(1, $result['hidden']);
    }

    public function testProductsAreVisibleInTheRolesWebsitesOrInNone(): void
    {
        $products = [];
        foreach ([2, 6, 7, 8, 9] as $id) {
            $products[] = ['entity_type' => 'product', 'id' => $id];
        }

        $result = $this->contentScope([1], [], null, self::CATALOG)->filter($products);

        // Product 6 is only in website 2, product 9 no longer exists.
        $this->assertSame([2, 7, 8], array_column($result['visible'], 'id'));
        $this->assertSame(2, $result['hidden']);
        $this->assertSame([6, 7, 8], array_column(
            $this->contentScope([2], [], null, self::CATALOG)->filter($products)['visible'],
            'id'
        ));
    }

    public function testCategoriesAreVisibleInTheTreesOfTheRolesStoreViews(): void
    {
        $categories = [];
        foreach ([4, 11, 5, 12, 1, 99] as $id) {
            $categories[] = ['entity_type' => 'catalog_category', 'entity_id' => (string) $id];
        }

        $result = $this->contentScope([1], [], null, self::CATALOG)->filter($categories, 'entity_type', 'entity_id');

        // Category 5 and root 12 are in store view 2's tree, category 99 no longer exists.
        $this->assertSame(['4', '11', '1'], array_column($result['visible'], 'entity_id'));
        $this->assertSame(3, $result['hidden']);
    }

    public function testAssetUsagesAreCheckedWithOneCatalogLookupPerType(): void
    {
        $catalogReader = $this->createMock(CatalogScopeReader::class);
        $catalogReader->expects($this->once())->method('productWebsiteIds')->with([6, 2])
            ->willReturn([6 => [2], 2 => [1]]);
        $catalogReader->expects($this->once())->method('categoryPaths')->with([5])->willReturn([5 => '1/12/5']);
        $filter = $this->contentScope([1], self::STORE_IDS, null, [], $catalogReader);
        $usages = [
            3 => [
                'hyva_and_catalog' => [
                    ['entity_type' => 'product', 'id' => 6],
                    ['entity_type' => 'cms_page', 'id' => 1],
                ],
                'magento_tracked' => [['entity_type' => 'catalog_product', 'entity_id' => '2']],
            ],
            4 => [
                'hyva_and_catalog' => [],
                'magento_tracked' => [['entity_type' => 'catalog_category', 'entity_id' => '5'],
                    ['entity_type' => 'catalog_product', 'entity_id' => '6']],
            ],
        ];

        $result = $filter->filterAssetUsages($usages);

        $this->assertSame([
            3 => [
                'hyva_and_catalog' => [['entity_type' => 'cms_page', 'id' => 1]],
                'magento_tracked' => [['entity_type' => 'catalog_product', 'entity_id' => '2']],
            ],
            4 => ['hyva_and_catalog' => [], 'magento_tracked' => []],
        ], $result['visible']);
        $this->assertSame([3 => 1, 4 => 2], $result['hidden']);
    }

    public function testRowsWithStoreViewsNeedNoLookup(): void
    {
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->expects($this->never())->method('getStoreIds');
        $rows = [
            ['id' => 1, 'store_ids' => [1]],
            ['id' => 2, 'store_ids' => ['2']],
            ['id' => 3, 'store_ids' => '0'],
            ['id' => 4, 'store_ids' => '2,1'],
            ['id' => 5, 'store_ids' => []],
        ];

        $result = $this->contentScope([1], [], $contentIndex)->filterByStoreIds($rows);

        $this->assertSame([1, 3, 4, 5], array_column($result['visible'], 'id'));
        $this->assertSame(1, $result['hidden']);
    }

    public function testAUsageReportLosesForeignEntriesFromItsTotalButStaysUsed(): void
    {
        $report = [
            'used' => true,
            'total' => 4,
            'design_config' => [['scope' => 'websites', 'scope_id' => 2]],
            'hyva_cms_content' => [['entity_type' => 'cms_page', 'id' => 2], ['entity_type' => 'cms_page', 'id' => 1]],
            'widget_instances' => [['instance_id' => 8, 'store_ids' => '2']],
        ];

        $result = $this->contentScope([1], self::STORE_IDS)->filterUsageReport(
            $report,
            ['hyva_cms_content', 'magento_cms_content'],
            ['widget_instances']
        );

        $this->assertTrue($result['used']);
        $this->assertSame(2, $result['total']);
        $this->assertSame([['entity_type' => 'cms_page', 'id' => 1]], $result['hyva_cms_content']);
        $this->assertSame([], $result['magento_cms_content']);
        $this->assertSame([], $result['widget_instances']);
        $this->assertSame($report['design_config'], $result['design_config']);
        $this->assertSame(2, $result['usages_hidden_by_permissions']);
    }

    /**
     * Usages of every kind, page 9 no longer exists.
     *
     * @return array
     * @phpstan-return list<array{entity_type: string, id: int}>
     */
    private function items(): array
    {
        $items = [];
        foreach ([['cms_page', 1], ['cms_page', 2], ['cms_page', 3], ['cms_block', 4], ['menu', 5], ['cms_page', 9],
            ['product', 2], ['category', 4]] as [$type, $id]) {
            $items[] = ['entity_type' => $type, 'id' => $id];
        }
        return $items;
    }
}
