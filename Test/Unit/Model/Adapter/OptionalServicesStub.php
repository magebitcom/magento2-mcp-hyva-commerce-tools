<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter;

use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;

/**
 * OptionalServiceFactory stub for adapter tests: hands out the given objects by class name.
 */
trait OptionalServicesStub
{
    /**
     * @param array<class-string, object> $services
     * @return OptionalServiceFactory
     */
    private function optionalServices(array $services): OptionalServiceFactory
    {
        $factory = $this->createMock(OptionalServiceFactory::class);
        $factory->method('get')->willReturnCallback(
            static fn (string $class): object => $services[$class] ?? throw new \LogicException('No stub for ' . $class)
        );
        return $factory;
    }
}
