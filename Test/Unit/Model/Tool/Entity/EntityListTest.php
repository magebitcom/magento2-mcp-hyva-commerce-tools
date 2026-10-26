<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\Entity;

use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageList;
use Magebit\Mcp\Api\ToolResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * page.list / block.list: SQL pagination with a COUNT total, the scope filter, and exact status filtering.
 *
 * @phpstan-import-type ContentRow from ContentIndex
 */
class EntityListTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @var ContentIndex&MockObject
     */
    private ContentIndex $index;

    protected function setUp(): void
    {
        $this->index = $this->getMockBuilder(ContentIndex::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findIds', 'countIds', 'getRows'])
            ->getMock();
    }

    public function testAPlainListIsCountedAndPaginatedInSql(): void
    {
        $filters = [
            'store_id' => 1, 'visible_store_ids' => null, 'search' => 'sale', 'liveview_enabled' => null,
            'legacy_only' => false, 'content' => null, 'exclude_ids' => [5],
        ];
        $this->index->expects($this->once())->method('countIds')->with('cms_page', $filters)->willReturn(120);
        $this->index->expects($this->once())->method('findIds')->with('cms_page', $filters, 50, 50)->willReturn([7]);
        $this->index->expects($this->once())->method('getRows')->with('cms_page', false, [7])
            ->willReturn([$this->row(7, ContentIndex::STATUS_PUBLISHED)]);

        $payload = $this->payload($this->tool(null)->execute(
            ['store_id' => 1, 'search' => 'sale', 'scheduled' => false, 'page' => 2]
        ));

        $this->assertSame(120, $payload['total']);
        $this->assertSame(2, $payload['page']);
        $this->assertSame(50, $payload['page_size']);
        $items = $this->items($payload);
        $this->assertSame([7], array_column($items, 'id'));
        $this->assertFalse($items[0]['scheduled']);
        $this->assertArrayHasKey('legacy', $items[0]);
    }

    public function testARestrictedCallerOnlyListsItsStoreViews(): void
    {
        $this->index->expects($this->once())->method('countIds')
            ->with('cms_page', $this->callback(static fn (array $f): bool => $f['visible_store_ids'] === [0, 1]))
            ->willReturn(0);
        $this->index->method('findIds')->willReturn([]);
        $this->index->expects($this->never())->method('getRows');

        $payload = $this->payload($this->tool([1])->execute([]));
        $this->assertSame(0, $payload['total']);
        $this->assertSame([], $payload['items']);
    }

    public function testAStatusThatComparesDraftAndPublishedIsCheckedOnTheCandidatesWithAnExactTotal(): void
    {
        $this->index->expects($this->never())->method('countIds');
        $this->index->expects($this->once())->method('findIds')
            ->with('cms_page', $this->callback(
                static fn (array $f): bool => $f['content'] === ContentIndex::CONTENT_PUBLISHED
            ))
            ->willReturn([1, 2, 3, 4]);
        $this->index->method('getRows')->willReturn([
            $this->row(1, ContentIndex::STATUS_PUBLISHED),
            $this->row(2, ContentIndex::STATUS_PUBLISHED_WITH_DRAFT),
            $this->row(3, ContentIndex::STATUS_PUBLISHED),
            $this->row(4, ContentIndex::STATUS_PUBLISHED),
        ]);

        $payload = $this->payload($this->tool(null)->execute(
            ['status' => ContentIndex::STATUS_PUBLISHED, 'page' => 2, 'page_size' => 2]
        ));

        $this->assertSame(3, $payload['total']);
        $this->assertSame([4], array_column($this->items($payload), 'id'));
    }

    /**
     * PageList over the mocked index.
     *
     * @param array|null $allowedStoreIds
     * @return PageList
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function tool(?array $allowedStoreIds): PageList
    {
        $schedules = $this->createMock(ScheduleService::class);
        $schedules->method('getPendingEntityIds')->willReturn([5]);
        return new PageList(
            $this->index,
            $this->createMock(ComponentTree::class),
            $schedules,
            $this->storeScope($allowedStoreIds)
        );
    }

    /**
     * Content row.
     *
     * @param int $id
     * @param string $status
     * @return array
     * @phpstan-return ContentRow
     */
    private function row(int $id, string $status): array
    {
        return [
            'type' => 'cms_page', 'id' => $id, 'identifier' => 'p' . $id, 'title' => 'P' . $id, 'is_active' => true,
            'store_ids' => [0], 'has_hyva_row' => true, 'liveview_enabled' => true, 'status' => $status,
            'has_draft' => false, 'is_published' => true, 'draft_differs' => false, 'uses_page_builder' => false,
            'has_magento_content' => false, 'updated_at' => '',
        ];
    }

    /**
     * Items of a list payload.
     *
     * @param array $payload
     * @return array
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return list<array<mixed>>
     */
    private function items(array $payload): array
    {
        $items = $payload['items'] ?? null;
        $this->assertIsArray($items);
        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * Decoded JSON payload.
     *
     * @param ToolResultInterface $result
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $this->assertFalse($result->isError());
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
