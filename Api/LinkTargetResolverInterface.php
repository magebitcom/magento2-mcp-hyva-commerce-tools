<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api;

/**
 * Looks up the catalog / CMS entities that Hyvä CMS link fields point to.
 */
interface LinkTargetResolverInterface
{
    /**
     * Get categories.
     *
     * @param array $ids
     * @param int $storeId
     * @return array
     * @phpstan-param list<int> $ids
     * @phpstan-return array<int, array{exists: bool, active: bool, include_in_menu: bool, name: string,
     *     has_url_rewrite: bool}>
     */
    public function getCategories(array $ids, int $storeId = 0): array;

    /**
     * Get products.
     *
     * @param array $skus
     * @param int $storeId
     * @return array
     * @phpstan-param list<string> $skus
     * @phpstan-return array<string, array{exists: bool, enabled: bool, name: string}>
     */
    public function getProducts(array $skus, int $storeId = 0): array;

    /**
     * Get cms pages.
     *
     * @param array $identifiers
     * @return array
     * @phpstan-param list<string> $identifiers
     * @phpstan-return array<string, array{exists: bool, active: bool, store_ids: list<int>}>
     */
    public function getCmsPages(array $identifiers): array;

    /**
     * Category subtree under a root, nested.
     *
     * @param int $rootId
     * @param int $depth Levels below the root to include.
     * @param int $storeId
     * @return array
     * @phpstan-return list<array{id: int, name: string, active: bool, include_in_menu: bool, has_url_rewrite: bool,
     *     level: int, children: list<mixed>}>
     */
    public function getCategoryTree(int $rootId, int $depth, int $storeId = 0): array;
}
