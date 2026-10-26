<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Schedule;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleConflictDetector;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use PHPUnit\Framework\TestCase;

/**
 * Conflicts naming content outside the caller's store views are dropped, release-level ones included, from the items
 * and store views the detection already read.
 */
class ConflictReportScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testConflictsWithAnOutOfScopeItemAreDropped(): void
    {
        $service = $this->service();
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('isScopeRestricted')->willReturn(true);
        $locator->expects($this->once())->method('prefetchScope')->with([['entity_type' => 'menu', 'id' => 3]]);
        $locator->expects($this->once())->method('allowsRead')->with('menu', 3)->willReturn(false);
        $detector = $this->detector([
            $this->conflict('superseded', [1], 'cms_page', 5),
            $this->conflict('store_view_collision', [1, 2]),
            $this->conflict('overdue', [2]),
            $this->conflict('menu_overdue', [3], 'menu', 3),
            $this->conflict('empty_release', []),
        ]);

        $scoped = $this->report($service, $locator, $detector, [1])->getConflictsWithinCallerScope();

        $this->assertSame(['superseded', 'empty_release'], array_column($scoped['conflicts'], 'code'));
        $this->assertSame(3, $scoped['hidden']);
    }

    public function testAnUnrestrictedCallerKeepsEveryConflictWithoutAScopeQuery(): void
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('isScopeRestricted')->willReturn(false);
        $locator->expects($this->never())->method('prefetchScope');
        $conflicts = [$this->conflict('overdue', [2])];

        $scoped = $this->report($this->service(), $locator, $this->detector($conflicts), null)
            ->getConflictsWithinCallerScope();

        $this->assertSame(['conflicts' => $conflicts, 'hidden' => 0], $scoped);
    }

    /**
     * Schedule service with pending items 1 (page 5), 2 (page 6) and 3 (menu 3), read exactly once.
     *
     * @return ScheduleService
     */
    private function service(): ScheduleService
    {
        $service = $this->createMock(ScheduleService::class);
        $service->expects($this->once())->method('getItems')->willReturn([
            $this->item(1, 'cms_page', 5),
            $this->item(2, 'cms_page', 6),
            $this->item(3, 'menu', 3),
        ]);
        $service->method('getReleases')->willReturn([]);
        return $service;
    }

    /**
     * Detector returning the conflicts.
     *
     * @param array $conflicts
     * @return ScheduleConflictDetector
     * @phpstan-param list<array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}> $conflicts
     */
    private function detector(array $conflicts): ScheduleConflictDetector
    {
        $detector = $this->createMock(ScheduleConflictDetector::class);
        $detector->method('detect')->willReturn($conflicts);
        return $detector;
    }

    /**
     * Report over the mocks: page 5 is on store view 1, page 6 on store view 2.
     *
     * @param ScheduleService $service
     * @param ContentLocator $locator
     * @param ScheduleConflictDetector $detector
     * @param array|null $allowedStoreIds
     * @return ConflictReport
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function report(
        ScheduleService $service,
        ContentLocator $locator,
        ScheduleConflictDetector $detector,
        ?array $allowedStoreIds
    ): ConflictReport {
        $index = $this->createMock(ContentIndex::class);
        $index->method('getRows')->willReturnCallback(fn (string $type): array => $type === 'cms_page'
            ? [$this->row(5, [1]), $this->row(6, [2])]
            : []);
        $index->expects($this->never())->method('getStoreIds');
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('getMenuRows')->willReturn([['id' => '3', 'identifier' => 'main']]);
        return new ConflictReport(
            $service,
            $detector,
            $index,
            $scanner,
            $locator,
            $this->storeScope($allowedStoreIds)
        );
    }

    /**
     * Pending schedule item row.
     *
     * @param int $itemId
     * @param string $type
     * @param int $entityId
     * @return array
     * @phpstan-return array{item_id: int, release_id: int, entity_type: string, entity_id: int, name: string,
     *     status: string, settings: string|null, version_history_id: string|null, creation_time: string,
     *     release_name: string, release_status: string, time_scheduled: string}
     */
    private function item(int $itemId, string $type, int $entityId): array
    {
        return ['item_id' => $itemId, 'release_id' => 1, 'entity_type' => $type, 'entity_id' => $entityId,
            'name' => '', 'status' => 'pending', 'settings' => null, 'version_history_id' => null,
            'creation_time' => '', 'release_name' => 'R', 'release_status' => 'pending', 'time_scheduled' => ''];
    }

    /**
     * Content index row of a page.
     *
     * @param int $id
     * @param array $storeIds
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>, has_hyva_row: bool, liveview_enabled: bool, status: string, has_draft: bool,
     *     is_published: bool, draft_differs: bool, uses_page_builder: bool, has_magento_content: bool,
     *     updated_at: string}
     */
    private function row(int $id, array $storeIds): array
    {
        return ['type' => 'cms_page', 'id' => $id, 'identifier' => 'p' . $id, 'title' => '', 'is_active' => true,
            'store_ids' => $storeIds, 'has_hyva_row' => true, 'liveview_enabled' => true, 'status' => '',
            'has_draft' => false, 'is_published' => true, 'draft_differs' => false, 'uses_page_builder' => false,
            'has_magento_content' => false, 'updated_at' => ''];
    }

    /**
     * Conflict.
     *
     * @param string $code
     * @param array $itemIds
     * @param string|null $type
     * @param int|null $entityId
     * @return array
     * @phpstan-param list<int> $itemIds
     * @phpstan-return array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}
     */
    private function conflict(string $code, array $itemIds, ?string $type = null, ?int $entityId = null): array
    {
        $conflict = ['severity' => 'warning', 'code' => $code, 'message' => '', 'release_ids' => [],
            'item_ids' => $itemIds];
        if ($type !== null && $entityId !== null) {
            $conflict['entity_type'] = $type;
            $conflict['entity_id'] = $entityId;
        }
        return $conflict;
    }
}
