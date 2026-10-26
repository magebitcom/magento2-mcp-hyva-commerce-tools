<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Model\WidgetType\WidgetTypeDispatcher;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;

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
     * @param ObjectManagerInterface $objectManager
     * @param WidgetTypeDispatcher $widgetTypeDispatcher
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly WidgetTypeDispatcher $widgetTypeDispatcher
    ) {
    }

    /**
     * Widget instance carrying the id and type of a hyva_admin_dashboard_widget_instance row.
     *
     * @param int $instanceId
     * @param string $widgetTypeId
     * @return WidgetInstanceInterface
     * @throws LocalizedException
     */
    public function create(int $instanceId, string $widgetTypeId): WidgetInstanceInterface
    {
        $instance = $this->objectManager->create(
            WidgetInstanceInterface::class,
            ['widgetTypeDispatcher' => $this->widgetTypeDispatcher]
        );
        if (!$instance instanceof WidgetInstanceInterface) {
            throw new LocalizedException(__('%1 could not be created.', WidgetInstanceInterface::class));
        }
        return $instance->setInstanceId($instanceId)->setWidgetTypeId($widgetTypeId);
    }
}
