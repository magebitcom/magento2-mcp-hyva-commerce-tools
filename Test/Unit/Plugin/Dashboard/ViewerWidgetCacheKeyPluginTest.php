<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Plugin\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Model\Cache\Type\AdminDashboard;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magebit\McpHyvaCommerceTools\Plugin\Dashboard\ViewerWidgetCacheKeyPlugin;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use PHPUnit\Framework\TestCase;

/**
 * A widget on a shared dashboard view must not serve one admin's scoped data to another (findings: unscoped widgets,
 * Menu Health audit-log link without mcp_audit).
 */
class ViewerWidgetCacheKeyPluginTest extends TestCase
{
    use StoreScopeStub;

    private const KEY = 'hyva_admin_dashboard:widget:magebit_menu_health_widget:display_data:4';

    public function testAnUnrestrictedAdminWithTheAuditLogKeepsHyvasKey(): void
    {
        $this->assertSame(self::KEY, $this->key(null, true, 'magebit_menu_health_widget'));
    }

    public function testOtherViewersGetAKeyOfTheirOwn(): void
    {
        $this->assertSame(self::KEY . ':stores-1-2', $this->key([2, 1], true, 'magebit_menu_health_widget'));
        $this->assertSame(self::KEY . ':no-audit-log', $this->key(null, false, 'magebit_menu_health_widget'));
        $this->assertSame(
            self::KEY . ':stores-1:no-audit-log',
            $this->key([1], false, 'magebit_content_health_widget')
        );
    }

    public function testMenuBuilderAccessAndSchedulingPermissionsSplitTheKey(): void
    {
        $this->assertSame(
            self::KEY . ':no-menu',
            $this->key(null, true, 'magebit_menu_health_widget', false)
        );
        $this->assertSame(
            self::KEY . ':no-schedule-cms_page-menu',
            $this->key(null, true, 'magebit_content_health_widget', true, ['cms_block'])
        );
    }

    public function testOtherWidgetTypesKeepHyvasKey(): void
    {
        $this->assertSame(self::KEY, $this->key([1], false, 'hyva_sales_widget'));
    }

    /**
     * Cache key for a viewer.
     *
     * @param array|null $allowedStoreIds
     * @param bool $auditAllowed
     * @param string $widgetType
     * @param bool $menuAllowed
     * @param array $schedulable Content types the viewer may schedule.
     * @return string
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<string> $schedulable
     */
    private function key(
        ?array $allowedStoreIds,
        bool $auditAllowed,
        string $widgetType,
        bool $menuAllowed = true,
        array $schedulable = ['cms_page', 'cms_block', 'menu']
    ): string {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->with(FeatureCheckerInterface::MENU_BUILDER)->willReturn($menuAllowed);
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('allowedTypes')->willReturnCallback(
            static fn (array $types): array => array_values(array_intersect($types, $schedulable))
        );
        $reader = $this->createMock(McpActivityReader::class);
        $reader->method('isAllowed')->willReturn($auditAllowed);
        $instance = $this->createMock(WidgetInstanceInterface::class);
        $instance->method('getWidgetTypeId')->willReturn($widgetType);
        return (new ViewerWidgetCacheKeyPlugin($this->storeScope($allowedStoreIds), $reader, $features, $acl))
            ->afterGetWidgetInstanceDisplayDataCacheKey(
                $this->createMock(AdminDashboard::class),
                self::KEY,
                $instance
            );
    }
}
