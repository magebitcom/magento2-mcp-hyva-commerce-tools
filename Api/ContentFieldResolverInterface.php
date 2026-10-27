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
 * Contributes one key to the hyva_cms.page.get / hyva_cms.block.get / hyva_menu.get response.
 */
interface ContentFieldResolverInterface extends FieldResolverInterface
{
    /**
     * Resolve.
     *
     * @param array $entity
     * @param array $args
     * @return mixed
     * @phpstan-param array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>} $entity
     * @phpstan-param array<string, mixed> $args
     */
    public function resolve(array $entity, array $args): mixed;
}
