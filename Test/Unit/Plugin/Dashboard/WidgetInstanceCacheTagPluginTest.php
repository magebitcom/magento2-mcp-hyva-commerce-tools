<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Plugin\Dashboard;

use Hyva\AdminDashboardFramework\Model\Cache\Type\AdminDashboard;
use Magebit\McpHyvaCommerceTools\Plugin\Dashboard\WidgetInstanceCacheTagPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Every cached variant of a widget instance's display data carries the instance tag widget.refresh cleans.
 */
class WidgetInstanceCacheTagPluginTest extends TestCase
{
    private const KEY = 'hyva_admin_dashboard:widget:magebit_menu_health_widget:display_data:4';

    public function testBaseAndViewerKeysGetTheInstanceTag(): void
    {
        foreach ([self::KEY, self::KEY . ':stores-1:no-audit-log'] as $key) {
            $this->assertSame(['HYVA_ADMIN_DASHBOARD', 'MAGEBIT_MCP_DASHBOARD_WIDGET_4'], $this->tags($key));
        }
    }

    public function testOtherEntriesKeepTheirTags(): void
    {
        $this->assertSame(['HYVA_ADMIN_DASHBOARD'], $this->tags('hyva_admin_dashboard:widget:x:display_data:44x'));
        $this->assertSame(['HYVA_ADMIN_DASHBOARD'], $this->tags('hyva_admin_dashboard:config'));
    }

    /**
     * Tags the plugin passes on for a save of the key.
     *
     * @param string $key
     * @return array
     * @phpstan-return array<mixed>
     */
    private function tags(string $key): array
    {
        return (new WidgetInstanceCacheTagPlugin())->beforeSave(
            $this->createMock(AdminDashboard::class),
            '{}',
            $key,
            ['HYVA_ADMIN_DASHBOARD'],
            null
        )[2];
    }
}
