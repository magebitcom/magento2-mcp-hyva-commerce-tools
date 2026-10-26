<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\WidgetInstance\WidgetInstanceInterface;
use Hyva\AdminDashboardFramework\Model\WidgetType\WidgetTypeDispatcher;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\WidgetInstanceFactory;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

class WidgetInstanceFactoryTest extends TestCase
{
    /**
     * The injected (lazy) dispatcher is handed to Hyvä's model, so it never builds the adminhtml-only one.
     *
     * @return void
     */
    public function testPassesTheInjectedDispatcherAndSetsTheRow(): void
    {
        $dispatcher = $this->createMock(WidgetTypeDispatcher::class);
        $instance = $this->createMock(WidgetInstanceInterface::class);
        $instance->expects($this->once())->method('setInstanceId')->with(7)->willReturnSelf();
        $instance->expects($this->once())->method('setWidgetTypeId')->with('w')->willReturnSelf();
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->once())->method('create')
            ->with(WidgetInstanceInterface::class, ['widgetTypeDispatcher' => $dispatcher])
            ->willReturn($instance);

        $this->assertSame($instance, (new WidgetInstanceFactory($objectManager, $dispatcher))->create(7, 'w'));
    }
}
