<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter;

use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

class OptionalServiceFactoryTest extends TestCase
{
    public function testAnAbsentPackageIsReportedWithoutTouchingTheObjectManager(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->never())->method('get');
        $this->expectException(LocalizedException::class);
        /** @var class-string<object> $absent */
        $absent = 'Hyva\\NotInstalled\\Model\\Service';
        (new OptionalServiceFactory($objectManager))->get($absent);
    }

    public function testAnInstalledServiceIsTheSharedInstance(): void
    {
        $service = new \ArrayObject();
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->once())->method('get')->with(\ArrayObject::class)->willReturn($service);

        $this->assertSame($service, (new OptionalServiceFactory($objectManager))->get(\ArrayObject::class));
    }
}
