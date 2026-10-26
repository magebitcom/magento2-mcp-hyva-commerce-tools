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
 * hyva_cms.schedule.list without an entity hides items of content outside the caller's store views.
 */
class ScheduleListScopeTest extends TestCase
{
    public function testItemsOfOutOfScopeContentAreHiddenAndCounted(): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturn([
            $this->item(1, 10, 'cms_page', 5),
            $this->item(2, 10, 'cms_page', 6),
            $this->item(3, 11, 'menu', 7),
        ]);
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        $locator = $this->createMock(ContentLocator::class);
        $locator->expects($this->once())->method('prefetchScope')->with([
            ['entity_type' => 'cms_page', 'id' => 5],
            ['entity_type' => 'cms_page', 'id' => 6],
            ['entity_type' => 'menu', 'id' => 7],
        ]);
        $locator->method('allowsRead')->willReturnCallback(static fn (string $type, int $id): bool => $id === 5);
        $time = $this->createMock(ScheduleTime::class);
        $time->method('toLocal')->willReturnArgument(0);
        $time->method('getStoreTimezone')->willReturn('UTC');

        $result = (new ScheduleList($service, $time, $locator, $acl))->execute([]);
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);

        $this->assertIsArray($payload);
        $this->assertSame(2, $payload['items_hidden_by_permissions']);
        $this->assertSame(1, $payload['total_releases']);
        $this->assertSame(5, $payload['releases'][0]['items'][0]['entity_id'] ?? null);
        $this->assertCount(1, $payload['releases'][0]['items'] ?? []);
    }

    /**
     * Scheduled item row.
     *
     * @param int $itemId
     * @param int $releaseId
     * @param string $type
     * @param int $entityId
     * @return array
     * @phpstan-return array{item_id: int, release_id: int, entity_type: string, entity_id: int, name: string,
     *     status: string, settings: string|null, version_history_id: string|null, creation_time: string,
     *     release_name: string, release_status: string, time_scheduled: string}
     */
    private function item(int $itemId, int $releaseId, string $type, int $entityId): array
    {
        return [
            'item_id' => $itemId, 'release_id' => $releaseId, 'entity_type' => $type, 'entity_id' => $entityId,
            'name' => 'Item', 'status' => 'pending', 'settings' => null, 'version_history_id' => null,
            'creation_time' => '2026-10-26 10:00:00', 'release_name' => 'R' . $releaseId,
            'release_status' => 'pending', 'time_scheduled' => '2026-11-01 10:00:00',
        ];
    }
}
