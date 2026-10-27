<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleList;
use PHPUnit\Framework\TestCase;

/**
 * hyva_cms.schedule.list reads a capped slice of the history statuses and reports when it was cut.
 */
class ScheduleListHistoryCapTest extends TestCase
{
    public function testHistoryStatusReadsACappedSliceAndReportsTruncation(): void
    {
        $captured = [];
        $rows = [];
        for ($i = 1; $i <= ScheduleList::HISTORY_ROW_CAP + 1; $i++) {
            $rows[] = $this->item($i);
        }
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturnCallback(
            function (array $filters) use (&$captured, $rows): array {
                $captured = $filters;
                return $rows;
            }
        );

        $payload = $this->payloadOf($service, ['status' => 'any', 'limit' => 500]);

        $this->assertSame(ScheduleList::HISTORY_ROW_CAP + 1, $captured['limit'] ?? null);
        $this->assertTrue($payload['truncated']);
        $this->assertSame(
            ScheduleList::HISTORY_ROW_CAP,
            $payload['total_releases'],
            'The cut falls on a release boundary: only the dropped row\'s release is gone, the oldest kept stays.'
        );
        $this->assertSame(
            ScheduleList::HISTORY_ROW_CAP + 1,
            $payload['releases'][0]['release_id'],
            'History lists the newest release first.'
        );
        $this->assertNotContains(1, array_column($payload['releases'], 'release_id'));
    }

    public function testTheReleaseOfTheDroppedRowIsLeftOutEntirely(): void
    {
        // Oldest first: row 1 (release 1) is dropped by the cap, release 1 continues with row 2 inside the window.
        $rows = [$this->item(1, 1), $this->item(2, 1)];
        for ($i = 3; $i <= ScheduleList::HISTORY_ROW_CAP + 1; $i++) {
            $rows[] = $this->item($i, $i);
        }
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturn($rows);

        $payload = $this->payloadOf($service, ['status' => 'any', 'limit' => 500]);

        $this->assertTrue($payload['truncated']);
        $ids = array_column($payload['releases'], 'release_id');
        $this->assertNotContains(1, $ids, 'The partial release of the dropped row is left out.');
        $this->assertSame(ScheduleList::HISTORY_ROW_CAP - 1, $payload['total_releases']);
    }

    public function testPendingKeepsOldestFirstOrder(): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturn([$this->item(1), $this->item(2)]);

        $payload = $this->payloadOf($service, []);

        $this->assertSame([1, 2], array_column($payload['releases'], 'release_id'));
    }

    public function testHistoryBelowTheCapIsNotTruncated(): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturn([$this->item(1)]);

        $payload = $this->payloadOf($service, ['status' => 'success']);

        $this->assertFalse($payload['truncated']);
        $this->assertSame(1, $payload['total_releases']);
    }

    public function testPendingIsNeverCapped(): void
    {
        $captured = ['limit' => 'unset'];
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturnCallback(
            function (array $filters) use (&$captured): array {
                $captured = $filters;
                return [];
            }
        );

        $payload = $this->payloadOf($service, []);

        $this->assertArrayNotHasKey('limit', $captured);
        $this->assertFalse($payload['truncated']);
    }

    /**
     * Execute the tool and decode its JSON payload.
     *
     * @param ScheduleService $service
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array{truncated: bool, total_releases: int, releases: list<array{release_id: int}>}
     */
    private function payloadOf(ScheduleService $service, array $arguments): array
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('allowsRead')->willReturn(true);
        $time = $this->createMock(ScheduleTime::class);
        $time->method('toLocal')->willReturnArgument(0);

        $result = (new ScheduleList($service, $time, $locator, $acl))->execute($arguments);
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        /** @var array{truncated: bool, total_releases: int, releases: list<array{release_id: int}>} $payload */
        return $payload;
    }

    /**
     * Scheduled item row of its own release.
     *
     * @param int $id
     * @param int|null $releaseId Defaults to a release of its own.
     * @return array
     * @phpstan-return array{item_id: int, release_id: int, entity_type: string, entity_id: int, name: string,
     *     status: string, settings: string|null, version_history_id: string|null, creation_time: string,
     *     release_name: string, release_status: string, time_scheduled: string}
     */
    private function item(int $id, ?int $releaseId = null): array
    {
        return [
            'item_id' => $id, 'release_id' => $releaseId ?? $id, 'entity_type' => 'cms_page', 'entity_id' => $id,
            'name' => 'Item', 'status' => 'success', 'settings' => null, 'version_history_id' => null,
            'creation_time' => '2026-10-26 10:00:00', 'release_name' => 'R' . ($releaseId ?? $id),
            'release_status' => 'success', 'time_scheduled' => '2026-10-01 10:00:00',
        ];
    }
}
