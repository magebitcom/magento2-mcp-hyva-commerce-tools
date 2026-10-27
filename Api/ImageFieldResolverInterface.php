<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api;

use Magebit\Mcp\Api\FieldResolverInterface;

/**
 * Contributes one key to the hyva_media.image.get response.
 */
interface ImageFieldResolverInterface extends FieldResolverInterface
{
    /**
     * Resolve.
     *
     * @param string $path Media path relative to pub/media.
     * @param array $args
     * @return mixed
     * @phpstan-param array<string, mixed> $args
     */
    public function resolve(string $path, array $args): mixed;
}
