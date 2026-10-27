<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaDashboard\Widget;

use Hyva\AdminDashboardFramework\Model\Cache\Type\AdminDashboard;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\WidgetInstanceFactory;
use Magebit\McpHyvaCommerceTools\Plugin\Dashboard\WidgetInstanceCacheTagPlugin;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;

/**
 * `hyva_dashboard.widget.refresh`: drop cached widget data so it is recalculated on the next dashboard load.
 *
 * Instances are read with SQL: the framework's instance repository depends on WidgetAuthInterface, which only
 * adminhtml DI provides, while /mcp runs in the frontend area. The cache key comes from Hyvä's own
 * AdminDashboard::getWidgetInstanceDisplayDataCacheKey() on an unsaved WidgetInstance model built for the row; the
 * per-viewer variants of that key (ViewerWidgetCacheKeyPlugin) are cleaned by the instance tag
 * WidgetInstanceCacheTagPlugin adds when the data is saved.
 */
class WidgetRefresh extends AbstractTool
{
    public const TOOL_NAME = 'hyva_dashboard.widget.refresh';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_dashboard_widget_refresh';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AdminDashboardFramework::dashboard';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param ResourceConnection $resourceConnection
     * @param AdminDashboard $cache
     * @param WidgetInstanceFactory $widgetInstanceFactory
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly ResourceConnection $resourceConnection,
        private readonly AdminDashboard $cache,
        private readonly WidgetInstanceFactory $widgetInstanceFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Refresh Dashboard Widget Data';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Clear the cached data of Hyvä Admin Dashboard widgets so they recalculate (e.g. content health, '
            . 'migration status, campaign calendar after edits). Select by widget_type (e.g. '
            . 'magebit_content_health_widget) and/or instance_ids. Clears the data cached for every viewer of the '
            . 'instance; it is rebuilt on the next dashboard load.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('widget_type', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Widget type id; every instance of it is refreshed.'))
            ->array('instance_ids', fn (ArrayBuilder $a) => $a->ofIntegers())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::ADMIN_DASHBOARD)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::ADMIN_DASHBOARD)
            );
        }
        $type = $this->stringArg($arguments, 'widget_type');
        $ids = $this->intListArg($arguments, 'instance_ids');
        if ($type === null && $ids === []) {
            return $this->softError('Pass widget_type and/or instance_ids.');
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            $this->resourceConnection->getTableName('hyva_admin_dashboard_widget_instance'),
            ['instance_id', 'widget_type_id']
        );
        if ($type !== null) {
            $select->where('widget_type_id = ?', $type);
        }
        if ($ids !== []) {
            $select->where('instance_id IN (?)', $ids);
        }
        $refreshed = [];
        foreach ($connection->fetchAll($select) as $row) {
            $instanceId = Cast::int($row['instance_id']);
            $widgetType = Cast::string($row['widget_type_id']);
            $this->cache->remove($this->cache->getWidgetInstanceDisplayDataCacheKey(
                $this->widgetInstanceFactory->create($instanceId, $widgetType)
            ));
            $this->cache->clean(
                \Zend_Cache::CLEANING_MODE_MATCHING_TAG,
                [WidgetInstanceCacheTagPlugin::tag($instanceId)]
            );
            $refreshed[] = ['instance_id' => $instanceId, 'widget_type' => $widgetType, 'cache_cleared' => true];
        }
        return $this->jsonResult(
            ['refreshed' => $refreshed, 'count' => count($refreshed)],
            ['count' => count($refreshed), 'widget_type' => $type]
        );
    }
}
