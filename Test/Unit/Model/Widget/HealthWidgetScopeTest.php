<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Widget;

use Hyva\AdminDashboardApi\Api\V1\WidgetContextInterface;
use Hyva\AdminDashboardApi\Api\V1\WidgetInstanceInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageChecker;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageEngineInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuValidator;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Model\Widget\CampaignAssetReadiness;
use Magebit\McpHyvaCommerceTools\Model\Widget\CampaignCalendar;
use Magebit\McpHyvaCommerceTools\Model\Widget\MediaHealth;
use Magebit\McpHyvaCommerceTools\Model\Widget\MenuHealth;
use Magebit\McpHyvaCommerceTools\Model\Widget\MigrationStatus;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard widgets show the content of the admin's websites/store views only (finding: unscoped widgets), and link
 * to the MCP audit log only for admins who may open it (finding: Menu Health audit-log link without mcp_audit).
 */
class HealthWidgetScopeTest extends TestCase
{
    use ContentScopeStub;

    private const AUDIT_LOG = 'magebit_mcp/auditlog/index';

    public function testMenuHealthLinksTheAuditLogOnlyWithTheAuditPermission(): void
    {
        $denied = $this->menuHealth(null, false)->getDisplayData($this->ctx(), $this->instance());
        $this->assertNotContains(self::AUDIT_LOG, array_column($this->list($denied, 'links'), 'url'));
        $this->assertSame(['Menus'], array_column($this->list($denied, 'sections'), 'title'));

        $allowed = $this->menuHealth(null, true)->getDisplayData($this->ctx(), $this->instance());
        $this->assertContains(self::AUDIT_LOG, array_column($this->list($allowed, 'links'), 'url'));
        $this->assertSame(['Menus', 'Last MCP menu actions'], array_column($this->list($allowed, 'sections'), 'title'));
    }

    public function testMenuHealthShowsOnlyTheAdminsMenus(): void
    {
        $restricted = $this->menuHealth([1], true)->getDisplayData($this->ctx(), $this->instance());
        $this->assertSame(['Main (main)'], array_column($this->rows($restricted), 'label'));
        $this->assertSame(1, $this->list($restricted, 'stats')[0]['value']);

        $all = $this->menuHealth(null, true)->getDisplayData($this->ctx(), $this->instance());
        $this->assertSame(2, $this->list($all, 'stats')[0]['value']);
    }

    public function testMediaHealthLinksTheAuditLogOnlyWithTheAuditPermission(): void
    {
        foreach ([false, true] as $allowed) {
            $data = $this->mediaHealth($allowed)->getDisplayData($this->ctx(), $this->instance());
            $links = array_column($this->list($data, 'links'), 'url');
            $this->assertSame($allowed, in_array(self::AUDIT_LOG, $links, true));
            $this->assertSame($allowed ? 2 : 1, count($this->list($data, 'sections')));
        }
    }

    public function testMigrationStatusCountsOnlyTheAdminsPages(): void
    {
        $include = 'not called';
        $data = $this->migrationStatus([1], $include)->getDisplayData($this->ctx(), $this->instance());
        $this->assertSame(1, $this->list($data, 'stats')[0]['value']);
        $this->assertIsCallable($include);
        $this->assertTrue($include([1]));
        $this->assertFalse($include([2]));

        $data = $this->migrationStatus(null, $include)->getDisplayData($this->ctx(), $this->instance());
        $this->assertSame(2, $this->list($data, 'stats')[0]['value']);
        $this->assertNull($include);
    }

    public function testCampaignCalendarListsOnlyTheAdminsScheduledContent(): void
    {
        $data = $this->campaignCalendar([1])->getDisplayData($this->ctx(), $this->instance());
        $this->assertSame(['Spring: Own'], array_column($this->rows($data), 'label'));

        $data = $this->campaignCalendar(null)->getDisplayData($this->ctx(), $this->instance());
        $this->assertCount(2, $this->rows($data));
    }

    public function testCampaignAssetReadinessChecksOnlyTheAdminsScheduledContent(): void
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('getItems')->willReturn([$this->item(2, 'Foreign', '+1 day')]);
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->never())->method('fetchPairs');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $widget = new CampaignAssetReadiness(
            $schedule,
            $this->createMock(ComponentTree::class),
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageChecker::class),
            $this->createMock(MediaService::class),
            $this->createMock(FeatureCheckerInterface::class),
            $this->createMock(MenuValidator::class),
            $resource,
            $this->createMock(UrlInterface::class),
            $this->contentScope([1], ['cms_page' => [1 => [1], 2 => [2]]])
        );

        $data = $widget->getDisplayData($this->ctx(), $this->instance());

        $this->assertSame(0, $this->list($data, 'stats')[0]['value']);
    }

    /**
     * Menu Health over a menu of store view 1 and one of store view 2.
     *
     * @param array|null $allowedStoreIds
     * @param bool $auditAllowed
     * @return MenuHealth
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function menuHealth(?array $allowedStoreIds, bool $auditAllowed): MenuHealth
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturn([
            ['id' => 1, 'title' => 'Main', 'identifier' => 'main', 'is_published' => true, 'store_ids' => [1]],
            ['id' => 2, 'title' => 'Other', 'identifier' => 'other', 'is_published' => true, 'store_ids' => [2]],
        ]);
        $menus = $this->createMock(MenuService::class);
        $menus->method('validate')->willReturn(['issues' => [], 'counts' => ['error' => 0, 'warning' => 0]]);
        $menus->method('getAssignments')->willReturn([]);
        return new MenuHealth(
            $features,
            $gateway,
            $menus,
            $this->activity($auditAllowed),
            $this->url(),
            $this->contentScope($allowedStoreIds)
        );
    }

    /**
     * Media Health without problems.
     *
     * @param bool $auditAllowed
     * @return MediaHealth
     */
    private function mediaHealth(bool $auditAllowed): MediaHealth
    {
        $auditor = $this->createMock(MediaAuditor::class);
        $auditor->method('audit')->willReturn(['scope' => 'all', 'images_checked' => 0, 'counts' => [],
            'config_issues' => [], 'images' => []]);
        $engines = $this->createMock(ImageEngineInfo::class);
        $engines->method('getEngines')->willReturn(
            ['gd' => ['available' => true], 'imagick' => ['available' => false]]
        );
        return new MediaHealth(
            $auditor,
            $this->createMock(MediaService::class),
            $engines,
            $this->activity($auditAllowed),
            $this->url()
        );
    }

    /**
     * Migration Status over a page of store view 1 and one of store view 2; $include gets the scan's store filter.
     *
     * @param array|null $allowedStoreIds
     * @param mixed $include
     * @return MigrationStatus
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function migrationStatus(?array $allowedStoreIds, mixed &$include): MigrationStatus
    {
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->method('getRows')->willReturn([
            ['id' => 1, 'liveview_enabled' => true, 'store_ids' => [1]],
            ['id' => 2, 'liveview_enabled' => false, 'store_ids' => [2]],
        ]);
        $migration = $this->createMock(MigrationService::class);
        $migration->method('scan')->willReturnCallback(
            static function (array $types, ?callable $includeStores) use (&$include): array {
                $include = $includeStores;
                return [];
            }
        );
        return new MigrationStatus($migration, $contentIndex, $this->url(), $this->contentScope($allowedStoreIds));
    }

    /**
     * Campaign Calendar over an upcoming item of page 1 (store view 1) and one of page 2 (store view 2).
     *
     * @param array|null $allowedStoreIds
     * @return CampaignCalendar
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function campaignCalendar(?array $allowedStoreIds): CampaignCalendar
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('getItems')->willReturn([
            $this->item(1, 'Own', '+1 day'),
            $this->item(2, 'Foreign', '+2 days'),
        ]);
        $conflicts = $this->createMock(ConflictReport::class);
        $conflicts->method('getConflictsWithinCallerScope')->willReturn(['conflicts' => [], 'hidden' => 0]);
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->method('getRows')->willReturn([]);
        return new CampaignCalendar(
            $schedule,
            $conflicts,
            $this->createMock(ScheduleTime::class),
            $this->createMock(ScopeConfigInterface::class),
            $contentIndex,
            $this->url(),
            $this->contentScope($allowedStoreIds, ['cms_page' => [1 => [1], 2 => [2]]])
        );
    }

    /**
     * Pending scheduled item of a page.
     *
     * @param int $pageId
     * @param string $name
     * @param string $when
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function item(int $pageId, string $name, string $when): array
    {
        return ['item_id' => $pageId, 'release_id' => 1, 'release_name' => 'Spring', 'name' => $name,
            'entity_type' => 'cms_page', 'entity_id' => $pageId, 'status' => 'pending',
            'time_scheduled' => gmdate('Y-m-d H:i:s', (int) strtotime($when))];
    }

    /**
     * Activity reader with or without the audit log permission.
     *
     * @param bool $allowed
     * @return McpActivityReader
     */
    private function activity(bool $allowed): McpActivityReader
    {
        $reader = $this->createMock(McpActivityReader::class);
        $reader->method('isAllowed')->willReturn($allowed);
        $reader->method('getRows')->willReturn([]);
        return $reader;
    }

    /**
     * URL builder returning the route.
     *
     * @return UrlInterface
     */
    private function url(): UrlInterface
    {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route): string => $route);
        return $url;
    }

    /**
     * Widget context.
     *
     * @return WidgetContextInterface
     */
    private function ctx(): WidgetContextInterface
    {
        return $this->createMock(WidgetContextInterface::class);
    }

    /**
     * Widget instance with default properties.
     *
     * @return WidgetInstanceInterface
     */
    private function instance(): WidgetInstanceInterface
    {
        return $this->createMock(WidgetInstanceInterface::class);
    }

    /**
     * Rows of the first section.
     *
     * @param mixed $data
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    private function rows(mixed $data): array
    {
        $rows = $this->list($data, 'sections')[0]['rows'] ?? null;
        $this->assertIsArray($rows);
        return array_values($rows);
    }

    /**
     * A list from the display data.
     *
     * @param mixed $data
     * @param string $key
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    private function list(mixed $data, string $key): array
    {
        $this->assertIsArray($data);
        $this->assertIsArray($data[$key] ?? null);
        return array_values($data[$key]);
    }
}
