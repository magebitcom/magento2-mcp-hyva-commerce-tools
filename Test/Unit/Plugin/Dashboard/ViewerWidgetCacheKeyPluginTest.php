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
     * @return string
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function key(?array $allowedStoreIds, bool $auditAllowed, string $widgetType): string
    {
        $reader = $this->createMock(McpActivityReader::class);
        $reader->method('isAllowed')->willReturn($auditAllowed);
        $instance = $this->createMock(WidgetInstanceInterface::class);
        $instance->method('getWidgetTypeId')->willReturn($widgetType);
        return (new ViewerWidgetCacheKeyPlugin($this->storeScope($allowedStoreIds), $reader))
            ->afterGetWidgetInstanceDisplayDataCacheKey(
                $this->createMock(AdminDashboard::class),
                self::KEY,
                $instance
            );
    }
}
