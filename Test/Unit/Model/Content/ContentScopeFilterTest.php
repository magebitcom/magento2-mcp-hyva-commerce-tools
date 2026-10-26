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
use PHPUnit\Framework\TestCase;

/**
 * Usage lists, audits and widgets hide content of other websites' store views from a restricted caller (finding:
 * unscoped image/audit lists).
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

    public function testAnUnrestrictedCallerGetsEverythingWithoutALookup(): void
    {
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->expects($this->never())->method('getStoreIds');
        $filter = $this->contentScope(null, [], $contentIndex);
        $items = $this->items();

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
    }

    public function testARestrictedCallerSeesOnlyContentOfItsStoreViewsAndEverythingElse(): void
    {
        $filter = $this->contentScope([1], self::STORE_IDS);

        $result = $filter->filter($this->items());

        // Page 2, block 4 and the deleted page 9 are hidden; products and categories are not store view content.
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
        $filter = $this->contentScope([1], [], $contentIndex);

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

        $result = $this->contentScope([1], self::STORE_IDS)->filter($tracked, 'entity_type', 'entity_id');

        $this->assertSame([$tracked[0], $tracked[2]], $result['visible']);
        $this->assertSame(1, $result['hidden']);
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
