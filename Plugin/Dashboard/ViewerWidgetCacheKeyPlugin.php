<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Plugin\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Model\Cache\Type\AdminDashboard;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;

/**
 * Hyvä caches a widget's display data per widget instance, and an instance on a shared dashboard view is shown to
 * every role of the view. This module's widgets depend on the viewer (website/store view restriction, MCP audit log
 * permission, Menu Builder access, Hyvä CMS scheduling per content type), so viewers other than an unrestricted admin
 * with all of these get a cache entry of their own; the common case keeps Hyvä's key. hyva_dashboard.widget.refresh
 * clears every variant through the instance tag of WidgetInstanceCacheTagPlugin.
 */
class ViewerWidgetCacheKeyPlugin
{
    /**
     * Widget types of this module whose data depends on the viewer.
     */
    private const WIDGET_TYPES = [
        'magebit_content_health_widget',
        'magebit_menu_health_widget',
        'magebit_media_health_widget',
        'magebit_campaign_calendar_widget',
        'magebit_migration_status_widget',
        'magebit_campaign_asset_readiness_widget',
    ];

    /**
     * @param StoreScopeGuard $storeScopeGuard
     * @param McpActivityReader $activityReader
     * @param FeatureCheckerInterface $featureChecker
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly StoreScopeGuard $storeScopeGuard,
        private readonly McpActivityReader $activityReader,
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * Add the viewer's scope and the permissions the widgets' data depends on to the key of this module's widgets.
     *
     * @param AdminDashboard $subject
     * @param string $result
     * @param WidgetInstanceInterface $widgetInstance
     * @return string
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterGetWidgetInstanceDisplayDataCacheKey(
        AdminDashboard $subject,
        string $result,
        WidgetInstanceInterface $widgetInstance
    ): string {
        if (!in_array($widgetInstance->getWidgetTypeId(), self::WIDGET_TYPES, true)) {
            return $result;
        }
        $viewer = [];
        $storeIds = $this->storeScopeGuard->restrictedStoreIds();
        if ($storeIds !== null) {
            sort($storeIds);
            $viewer[] = 'stores-' . implode('-', $storeIds);
        }
        if (!$this->activityReader->isAllowed()) {
            $viewer[] = 'no-audit-log';
        }
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER)) {
            $viewer[] = 'no-menu';
        }
        $unschedulable = array_diff(
            ContentLocator::TYPES,
            $this->contentAcl->allowedTypes(ContentLocator::TYPES, ContentAcl::VERB_SCHEDULING)
        );
        if ($unschedulable !== []) {
            $viewer[] = 'no-schedule-' . implode('-', $unschedulable);
        }
        return $viewer === [] ? $result : $result . ':' . implode(':', $viewer);
    }
}
