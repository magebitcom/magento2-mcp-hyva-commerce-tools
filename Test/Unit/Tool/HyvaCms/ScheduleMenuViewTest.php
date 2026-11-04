<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCancel;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleDetectConflicts;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuScheduleCancel;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuScheduleCreate;
use PHPUnit\Framework\TestCase;

/**
 * T-145: the schedule tools keep menus out of reach of a role without Hyva_MenuBuilder::menu_view (T-144 rule).
 */
class ScheduleMenuViewTest extends TestCase
{
    /**
     * @return array<string, array{0: bool}>
     */
    public static function menuViewProvider(): array
    {
        return ['without menu_view' => [false], 'with menu_view' => [true]];
    }

    /**
     * @dataProvider menuViewProvider
     * @param bool $menusViewable
     */
    public function testListDropsMenuItemsTheCallerMayNotView(bool $menusViewable): void
    {
        $service = $this->createMock(ScheduleService::class);
        $service->method('getItems')->willReturn([
            $this->item(1, 10, 'cms_page', 5),
            $this->item(2, 11, 'menu', 7),
        ]);
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('allowsRead')->willReturn(true);
        $time = $this->createMock(ScheduleTime::class);
        $time->method('toLocal')->willReturnArgument(0);

        $tool = new ScheduleList($service, $time, $locator, $acl, $this->scopeFilter($menusViewable));
        $payload = $this->payload($tool->execute([]));

        $types = [];
        foreach (Cast::array($payload['releases'] ?? null) as $release) {
            foreach (Cast::array(Cast::stringKeyed($release)['items'] ?? null) as $item) {
                $types[] = Cast::stringKeyed($item)['entity_type'] ?? null;
            }
        }
        $this->assertSame($menusViewable ? ['cms_page', 'menu'] : ['cms_page'], $types);
        $this->assertSame($menusViewable ? 0 : 1, $payload['items_hidden_by_permissions'] ?? null);
        $this->assertSame($menusViewable ? 2 : 1, $payload['total_releases'] ?? null);
    }

    /**
     * @dataProvider menuViewProvider
     * @param bool $menusViewable
     */
    public function testConflictsAreAskedForViewableTypesOnly(bool $menusViewable): void
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('allowedTypes')->willReturnArgument(0);
        $report = $this->createMock(ConflictReport::class);
        $report->expects($this->once())->method('getConflictsWithinCallerScope')
            ->with(60, null, null, $menusViewable ? ContentLocator::TYPES : ['cms_page', 'cms_block'])
            ->willReturn(['conflicts' => [], 'hidden' => 0]);

        $tool = new ScheduleDetectConflicts(
            $report,
            $this->createMock(ContentLocator::class),
            $acl,
            $this->scopeFilter($menusViewable)
        );
        $this->assertFalse($tool->execute([])->isError());
    }

    /**
     * @dataProvider menuViewProvider
     * @param bool $menusViewable
     */
    public function testCreateRepliesWithConflictsOfViewableTypesOnly(bool $menusViewable): void
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn([
            'type' => 'cms_page', 'id' => 4, 'identifier' => 'home', 'title' => 'Home', 'is_active' => true,
            'store_ids' => [0],
        ]);
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('schedule')
            ->willReturn(['release_id' => 9, 'item_id' => 11, 'release_created' => false, 'reactivated' => false]);
        $report = $this->createMock(ConflictReport::class);
        $report->expects($this->once())->method('getConflictsWithinCallerScope')
            ->with(60, 'cms_page', 4, $menusViewable ? null : ['cms_page', 'cms_block'])
            ->willReturn(['conflicts' => [], 'hidden' => 0]);

        $tool = new ScheduleCreate(
            $locator,
            $schedule,
            $this->createMock(ScheduleTime::class),
            $report,
            $this->createMock(ContentAcl::class),
            $this->scopeFilter($menusViewable)
        );
        $this->assertFalse($tool->execute(['entity_type' => 'cms_page', 'id' => 4, 'release_id' => 9])->isError());
    }

    public function testCallsTargetingAMenuNeedMenuView(): void
    {
        $acl = $this->realAcl();
        $cases = [
            'create' => [ScheduleCreate::class, ['entity_type' => 'menu', 'id' => 2], ['schedule_save', 'view']],
            'create, menu tool' => [MenuScheduleCreate::class, ['id' => 2], ['schedule_save', 'view']],
            'cancel, menu tool' => [MenuScheduleCancel::class, ['item_ids' => [3]], ['schedule_cancel', 'view']],
            'list of a menu' => [ScheduleList::class, ['entity_type' => 'menu', 'id' => 2], ['scheduling', 'view']],
            'conflicts of a menu' => [
                ScheduleDetectConflicts::class,
                ['entity_type' => 'menu', 'id' => 2],
                ['scheduling', 'view'],
            ],
        ];
        foreach ($cases as $label => [$class, $arguments, $verbs]) {
            $tool = $this->withAcl($class, $acl);
            $this->assertSame(
                array_map(static fn (string $verb): string => 'Hyva::menu_' . $verb, $verbs),
                $tool->getContextualAclResources($arguments),
                $label
            );
        }
    }

    public function testCallsTargetingOtherTypesNeedNoView(): void
    {
        $acl = $this->realAcl();
        $this->assertSame(
            ['Hyva::cms_page_schedule_save'],
            $this->withAcl(ScheduleCreate::class, $acl)->getContextualAclResources(['entity_type' => 'cms_page'])
        );
        $this->assertSame(
            ['Hyva::cms_block_scheduling'],
            $this->withAcl(ScheduleList::class, $acl)->getContextualAclResources(['entity_type' => 'cms_block'])
        );
        $this->assertSame([], $this->withAcl(ScheduleList::class, $acl)->getContextualAclResources([]));
    }

    /**
     * @dataProvider menuViewProvider
     * @param bool $menusViewable
     */
    public function testCancelRefusesMenuItemsTheCallerMayNotView(bool $menusViewable): void
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('allowsWrite')->willReturn(true);
        $callback = null;
        $service = $this->createMock(ScheduleService::class);
        $service->method('cancel')->willReturnCallback(
            static function (array $items, ?int $release, callable $mayCancel) use (&$callback): array {
                $callback = $mayCancel;
                return ['cancelled_items' => [], 'cancelled_releases' => []];
            }
        );

        (new ScheduleCancel($service, $acl, $locator, $this->scopeFilter($menusViewable)))
            ->execute(['item_ids' => [1]]);

        $this->assertIsCallable($callback);
        $this->assertSame($menusViewable, (bool) $callback('menu', 1));
        $this->assertTrue((bool) $callback('cms_page', 1));
    }

    /**
     * Scope filter whose viewable types leave out menus for a caller without menu_view.
     *
     * @param bool $menusViewable
     * @return ContentScopeFilter
     */
    private function scopeFilter(bool $menusViewable): ContentScopeFilter
    {
        $filter = $this->createMock(ContentScopeFilter::class);
        $filter->method('viewableTypes')->willReturnCallback(
            static fn (array $types): array => $menusViewable
                ? $types
                : array_values(array_filter($types, static fn (string $type): bool => $type !== 'menu'))
        );
        return $filter;
    }

    /**
     * Content ACL over Hyvä resources named "Hyva::<type>_<verb>".
     *
     * @return ContentAcl
     */
    private function realAcl(): ContentAcl
    {
        $authorization = $this->createMock(ContentTypeAuthorization::class);
        $authorization->method('getResource')->willReturnCallback(
            static fn (string $type, string $verb): string => 'Hyva::' . $type . '_' . $verb
        );
        return new ContentAcl($authorization, $this->createMock(CallerContext::class));
    }

    /**
     * Tool with only its content ACL set (the ACL checks need nothing else).
     *
     * @param class-string<ScheduleList|ScheduleCreate|ScheduleCancel|ScheduleDetectConflicts> $class
     * @param ContentAcl $acl
     * @return ScheduleList|ScheduleCreate|ScheduleCancel|ScheduleDetectConflicts
     */
    private function withAcl(string $class, ContentAcl $acl): object
    {
        $reflection = new \ReflectionClass($class);
        $tool = $reflection->newInstanceWithoutConstructor();
        // The menu variants inherit the private property from the generic tool.
        while (!$reflection->hasProperty('contentAcl') && ($parent = $reflection->getParentClass()) !== false) {
            $reflection = $parent;
        }
        $reflection->getProperty('contentAcl')->setValue($tool, $acl);
        return $tool;
    }

    /**
     * Decoded JSON reply.
     *
     * @param \Magebit\Mcp\Api\ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(\Magebit\Mcp\Api\ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        return Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));
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
