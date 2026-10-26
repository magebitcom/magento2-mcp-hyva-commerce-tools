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
use PHPUnit\Framework\TestCase;

/**
 * Conflicts naming content outside the caller's store views are dropped, release-level ones included.
 */
class ConflictReportScopeTest extends TestCase
{
    public function testConflictsWithAnOutOfScopeItemAreDropped(): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->expects($this->once())->method('getItems')->willReturn([
            ['item_id' => 1, 'entity_type' => 'cms_page', 'entity_id' => 5],
            ['item_id' => 2, 'entity_type' => 'cms_page', 'entity_id' => 6],
        ]);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('isScopeRestricted')->willReturn(true);
        $locator->expects($this->once())->method('prefetchScope');
        $locator->method('allowsRead')->willReturnCallback(static fn (string $type, int $id): bool => $id === 5);
        $conflicts = [
            $this->conflict('superseded', [1], 'cms_page', 5),
            $this->conflict('store_view_collision', [1, 2]),
            $this->conflict('overdue', [2]),
            $this->conflict('empty_release', []),
        ];

        $visible = $this->report($service, $locator)->withinCallerScope($conflicts);

        $this->assertSame(['superseded', 'empty_release'], array_column($visible, 'code'));
    }

    public function testAnUnrestrictedCallerKeepsEveryConflictWithoutAQuery(): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->expects($this->never())->method('getItems');
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('isScopeRestricted')->willReturn(false);
        $conflicts = [$this->conflict('overdue', [2])];

        $this->assertSame($conflicts, $this->report($service, $locator)->withinCallerScope($conflicts));
    }

    /**
     * Report over the mocks.
     *
     * @param ScheduleService $service
     * @param ContentLocator $locator
     * @return ConflictReport
     */
    private function report(ScheduleService $service, ContentLocator $locator): ConflictReport
    {
        return new ConflictReport(
            $service,
            $this->createMock(ScheduleConflictDetector::class),
            $this->createMock(ContentIndex::class),
            $this->createMock(ContentScanner::class),
            $locator
        );
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
