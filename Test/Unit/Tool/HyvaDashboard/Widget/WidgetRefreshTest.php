<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaDashboard\Widget;

use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Model\Cache\Type\AdminDashboard;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\WidgetInstanceFactory;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaDashboard\Widget\WidgetRefresh;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class WidgetRefreshTest extends TestCase
{
    use ToolPayloadTrait;

    /**
     * The key comes from Hyvä's own method on a model carrying the instance row.
     *
     * @return void
     */
    public function testCacheKeyComesFromHyvaCacheType(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['instance_id' => '7', 'widget_type_id' => 'magebit_content_health_widget'],
        ]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $cache = $this->getMockBuilder(AdminDashboard::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['remove', 'clean'])
            ->getMock();
        $cache->expects($this->once())->method('remove')
            ->with('hyva_admin_dashboard:widget:magebit_content_health_widget:display_data:7');
        // The per-viewer variants of the key carry the instance tag and are cleaned with it.
        $cache->expects($this->once())->method('clean')
            ->with(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, ['MAGEBIT_MCP_DASHBOARD_WIDGET_7'])
            ->willReturn(true);

        $instance = $this->createMock(WidgetInstanceInterface::class);
        $instance->method('getInstanceId')->willReturn(7);
        $instance->method('getWidgetTypeId')->willReturn('magebit_content_health_widget');
        $factory = $this->createMock(WidgetInstanceFactory::class);
        $factory->expects($this->once())->method('create')
            ->with(7, 'magebit_content_health_widget')
            ->willReturn($instance);

        $tool = new WidgetRefresh($featureChecker, $resource, $cache, $factory);
        $result = $tool->execute(['instance_ids' => [7]]);

        $this->assertSame(1, $this->at($result, 'count'));
        $this->assertSame('magebit_content_health_widget', $this->at($result, 'refreshed.0.widget_type'));
    }
}
