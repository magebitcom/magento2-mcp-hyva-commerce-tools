<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Plugin\Dashboard;

use Hyva\AdminDashboardFramework\Model\Cache\Type\AdminDashboard;

/**
 * Tags a widget instance's cached display data with the instance, so hyva_dashboard.widget.refresh can clear every
 * per-viewer variant ViewerWidgetCacheKeyPlugin creates, not only Hyvä's base key.
 *
 * Hyvä's AdminDashboard::saveWidgetInstanceDisplayData() saves through save() with its own tag only; the display data
 * key is `hyva_admin_dashboard:widget:<type>:display_data:<instance id>`, optionally followed by a viewer suffix.
 */
class WidgetInstanceCacheTagPlugin
{
    private const TAG_PREFIX = 'MAGEBIT_MCP_DASHBOARD_WIDGET_';
    private const KEY_PATTERN = '/^hyva_admin_dashboard:widget:[^:]+:display_data:(\d+)(?::|$)/';

    /**
     * Cache tag shared by every cached display data variant of a widget instance.
     *
     * @param int $instanceId
     * @return string
     */
    public static function tag(int $instanceId): string
    {
        return self::TAG_PREFIX . $instanceId;
    }

    /**
     * Add the instance tag when widget display data is saved.
     *
     * @param AdminDashboard $subject
     * @param mixed $data
     * @param mixed $identifier
     * @param array $tags
     * @param mixed $lifeTime
     * @return array
     * @phpstan-param array<mixed> $tags
     * @phpstan-return array{0: mixed, 1: mixed, 2: array<mixed>, 3: mixed}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(
        AdminDashboard $subject,
        mixed $data,
        mixed $identifier,
        array $tags = [],
        mixed $lifeTime = null
    ): array {
        if (is_string($identifier) && preg_match(self::KEY_PATTERN, $identifier, $match)) {
            $tags[] = self::tag((int) $match[1]);
        }
        return [$data, $identifier, $tags, $lifeTime];
    }
}
