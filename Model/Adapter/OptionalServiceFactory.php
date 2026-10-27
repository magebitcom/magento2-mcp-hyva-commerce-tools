<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for services of optional Hyvä packages (Menu Builder, Media Optimization, Form Builder, ...).
 *
 * A constructor that names a class of an absent package breaks `setup:di:compile` (it resolves every constructor
 * argument of every class in the module). The adapters therefore never type-hint those classes in a constructor:
 * they ask this factory, by class name, at call time, which is only after the tool's feature check said the package
 * is installed and enabled. It works like Magento's generated factories (object manager behind a class name), and
 * returns the shared instance constructor injection would have given.
 */
class OptionalServiceFactory
{
    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Shared instance of an optional package's service.
     *
     * @param string $class
     * @return object
     * @throws LocalizedException When the package is not installed.
     * @phpstan-template T of object
     * @phpstan-param class-string<T> $class
     * @phpstan-return T
     */
    public function get(string $class): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new LocalizedException(__('The optional package providing %1 is not installed.', $class));
        }
        $service = $this->objectManager->get($class);
        if (!$service instanceof $class) {
            throw new LocalizedException(__('%1 could not be created.', $class));
        }
        return $service;
    }
}
