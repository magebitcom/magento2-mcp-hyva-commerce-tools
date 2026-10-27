<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterfaceFactory;
use Hyva\AdminDashboardFramework\Model\WidgetType\WidgetTypeDispatcher;

/**
 * Unsaved Hyvä widget instance models for the /mcp (frontend) area.
 *
 * Hyvä's WidgetInstance builds a WidgetTypeDispatcher when none is passed, and the dispatcher needs
 * WidgetAuthInterface, which only adminhtml DI provides. This factory passes a lazy dispatcher (di.xml Proxy) that is
 * only built if getDisplayData() or a property lookup runs, which the MCP tools never call. Hyvä behaviour for other
 * code is unchanged.
 */
class WidgetInstanceFactory
{
    /**
     * @param WidgetInstanceInterfaceFactory $widgetInstanceFactory
     * @param WidgetTypeDispatcher $widgetTypeDispatcher
     */
    public function __construct(
        private readonly WidgetInstanceInterfaceFactory $widgetInstanceFactory,
        private readonly WidgetTypeDispatcher $widgetTypeDispatcher
    ) {
    }

    /**
     * Widget instance carrying the id and type of a hyva_admin_dashboard_widget_instance row.
     *
     * @param int $instanceId
     * @param string $widgetTypeId
     * @return WidgetInstanceInterface
     */
    public function create(int $instanceId, string $widgetTypeId): WidgetInstanceInterface
    {
        return $this->widgetInstanceFactory->create(['widgetTypeDispatcher' => $this->widgetTypeDispatcher])
            ->setInstanceId($instanceId)
            ->setWidgetTypeId($widgetTypeId);
    }
}
