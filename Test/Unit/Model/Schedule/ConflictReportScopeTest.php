<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Schedule;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
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

    public function testOnlyTheScheduledEntitiesAreReadWithoutContent(): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturn([
            $this->item(1, 'cms_page', 5),
            $this->item(2, 'cms_page', 5),
            $this->item(3, 'menu', 3),
        ]);
        $service->method('getReleases')->willReturn([]);
        $index = $this->createMock(ContentIndex::class);
        $index->expects($this->never())->method('getRows');
        $calls = [];
        $index->method('getIdentifiersByIds')->willReturnCallback(
            function (string $type, array $ids) use (&$calls): array {
                $calls[] = [$type, $ids];
                return [];
            }
        );
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $locator = $this->createMock(ContentLocator::class);
        $report = new ConflictReport(
            $service,
            $this->detector([]),
            $index,
            $featureChecker,
            $locator,
            $this->storeScope(null)
        );

        $report->getConflicts();

        $this->assertSame([['cms_page', [5]], ['menu', [3]]], $calls);
    }

    public function testTheEntityFilterKeepsCollisionsOfEitherEntityOnly(): void
    {
        $collision = $this->conflict('store_view_collision', [1, 2], 'cms_page', 5)
            + ['entity_ids' => [5, 6]];
        $locator = $this->createMock(ContentLocator::class);
        $report = $this->report($this->service(false), $locator, $this->detector([$collision]), null);

        $this->assertSame([$collision], $report->getConflicts(60, 'cms_page', 6));
        $this->assertSame([], $report->getConflicts(60, 'cms_page', 9));
        $this->assertSame([], $report->getConflicts(60, 'cms_block'));
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
     * @param bool $once
     * @return ScheduleService
     */
    private function service(bool $once = true): ScheduleService
    {
        $service = $this->createMock(ScheduleService::class);
        $service->expects($once ? $this->once() : $this->atLeastOnce())->method('getItems')->willReturn([
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
        $index->expects($this->never())->method('getRows');
        $index->expects($this->never())->method('getStoreIds');
        $index->method('getIdentifiersByIds')->willReturnCallback(
            fn (string $type, array $ids): array => match ($type) {
                'cms_page' => [5 => ['identifier' => 'p5', 'store_ids' => [1]],
                    6 => ['identifier' => 'p6', 'store_ids' => [2]]],
                'menu' => [3 => ['identifier' => 'main', 'store_ids' => []]],
                default => [],
            }
        );
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        return new ConflictReport(
            $service,
            $detector,
            $index,
            $featureChecker,
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
