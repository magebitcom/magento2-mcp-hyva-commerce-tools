<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Security\CatalogScopeReader;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Hides entities outside the caller's website/store view restriction from usage lists, audits and widgets, with
 * the read rules of StoreScopeGuard (Hyvä Advanced Admin Permissions):
 * - CMS pages, blocks, menus and widget instances: by their store views;
 * - products (product, catalog_product): by their websites, a product in no website stays visible;
 * - categories (category, catalog_category): by the root category of their tree.
 * Other entries (media files, configuration, ...) pass unchanged.
 *
 * Unrestricted callers get their input back without any lookup. Restricted callers cost one query per entity type
 * and request (ContentLocator::prefetchScope(), CatalogScopeReader); entities that no longer exist count as hidden.
 */
class ContentScopeFilter
{
    private const PRODUCT_TYPES = ['product', 'catalog_product'];
    private const CATEGORY_TYPES = ['category', 'catalog_category'];
    private const KIND_CONTENT = 'content';
    private const KIND_PRODUCT = 'product';
    private const KIND_CATEGORY = 'category';

    /**
     * @var array<int, list<int>|null> Websites per product id read in this request; null = no such product.
     */
    private array $productWebsiteIds = [];

    /**
     * @var array<int, string|null> Tree path per category id read in this request; null = no such category.
     */
    private array $categoryPaths = [];

    /**
     * @param ContentLocator $contentLocator
     * @param StoreScopeGuard $storeScopeGuard
     * @param CatalogScopeReader $catalogScopeReader
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly StoreScopeGuard $storeScopeGuard,
        private readonly CatalogScopeReader $catalogScopeReader
    ) {
    }

    /**
     * Whether the caller's role is restricted to some websites or store views.
     *
     * @return bool
     */
    public function isRestricted(): bool
    {
        return $this->contentLocator->isScopeRestricted();
    }

    /**
     * Whether the caller may see an entity assigned to these store views.
     *
     * @param array $storeIds
     * @return bool
     * @phpstan-param list<int> $storeIds
     */
    public function allowsStores(array $storeIds): bool
    {
        return $this->storeScopeGuard->allowsRead($storeIds);
    }

    /**
     * Keep the entries the caller may see.
     *
     * @param array $items Entries naming an entity by type and id; types without a scope are kept.
     * @param string $typeKey
     * @param string $idKey
     * @return array
     * @phpstan-template T of array<string, mixed>
     * @phpstan-param list<T> $items
     * @phpstan-return array{visible: list<T>, hidden: int}
     */
    public function filter(array $items, string $typeKey = 'entity_type', string $idKey = 'id'): array
    {
        $groups = $this->filterGrouped([$items], $typeKey, $idKey);
        return ['visible' => $groups['visible'][0] ?? [], 'hidden' => $groups['hidden'][0] ?? 0];
    }

    /**
     * Keep the entries the caller may see in several lists at once (one lookup for all of them).
     *
     * @param array $groups Lists keyed by e.g. media path.
     * @param string $typeKey
     * @param string $idKey
     * @return array Hidden entries per list.
     * @phpstan-template K of array-key
     * @phpstan-template T of array<string, mixed>
     * @phpstan-param array<K, list<T>> $groups
     * @phpstan-return array{visible: array<K, list<T>>, hidden: array<K, int>}
     */
    public function filterGrouped(array $groups, string $typeKey = 'entity_type', string $idKey = 'id'): array
    {
        $hidden = array_map(static fn (array $items): int => 0, $groups);
        if (!$this->isRestricted()) {
            return ['visible' => $groups, 'hidden' => $hidden];
        }
        $this->prefetch([[array_merge([], ...array_values($groups)), $typeKey, $idKey]]);
        $visible = [];
        foreach ($groups as $key => $items) {
            $visible[$key] = [];
            foreach ($items as $item) {
                if ($this->allowsItem($item, $typeKey, $idKey)) {
                    $visible[$key][] = $item;
                } else {
                    $hidden[$key]++;
                }
            }
        }
        return ['visible' => $visible, 'hidden' => $hidden];
    }

    /**
     * Keep the usages the caller may see of several media assets (one lookup for all of them).
     *
     * @param array $usages Per asset: Hyvä/catalog usages (entity_type, id) and Magento-tracked ones (entity_type,
     *     entity_id).
     * @return array Hidden usages per asset.
     * @phpstan-template K of array-key
     * @phpstan-template T of array<string, mixed>
     * @phpstan-template U of array<string, mixed>
     * @phpstan-param array<K, array{hyva_and_catalog: list<T>, magento_tracked: list<U>}> $usages
     * @phpstan-return array{visible: array<K, array{hyva_and_catalog: list<T>, magento_tracked: list<U>}>,
     *     hidden: array<K, int>}
     */
    public function filterAssetUsages(array $usages): array
    {
        $hidden = array_map(static fn (array $usage): int => 0, $usages);
        if (!$this->isRestricted()) {
            return ['visible' => $usages, 'hidden' => $hidden];
        }
        $this->prefetch([
            [array_merge([], ...array_values(array_column($usages, 'hyva_and_catalog'))), 'entity_type', 'id'],
            [array_merge([], ...array_values(array_column($usages, 'magento_tracked'))), 'entity_type', 'entity_id'],
        ]);
        $visible = [];
        foreach ($usages as $key => $usage) {
            $content = array_values(array_filter(
                $usage['hyva_and_catalog'],
                fn (array $item): bool => $this->allowsItem($item, 'entity_type', 'id')
            ));
            $tracked = array_values(array_filter(
                $usage['magento_tracked'],
                fn (array $item): bool => $this->allowsItem($item, 'entity_type', 'entity_id')
            ));
            $visible[$key] = ['hyva_and_catalog' => $content, 'magento_tracked' => $tracked];
            $hidden[$key] = count($usage['hyva_and_catalog']) - count($content)
                + count($usage['magento_tracked']) - count($tracked);
        }
        return ['visible' => $visible, 'hidden' => $hidden];
    }

    /**
     * Keep the rows the caller may see, for rows that carry their own store views (no lookup needed).
     *
     * @param array $rows
     * @param string $storeIdsKey A list of store view ids, or a comma-separated string (widget instances).
     * @return array
     * @phpstan-template T of array<string, mixed>
     * @phpstan-param list<T> $rows
     * @phpstan-return array{visible: list<T>, hidden: int}
     */
    public function filterByStoreIds(array $rows, string $storeIdsKey = 'store_ids'): array
    {
        if (!$this->isRestricted()) {
            return ['visible' => $rows, 'hidden' => 0];
        }
        $visible = [];
        foreach ($rows as $row) {
            $storeIds = $row[$storeIdsKey] ?? [];
            $storeIds = Cast::intList(is_string($storeIds) ? explode(',', $storeIds) : $storeIds);
            if ($this->storeScopeGuard->allowsRead($storeIds)) {
                $visible[] = $row;
            }
        }
        return ['visible' => $visible, 'hidden' => count($rows) - count($visible)];
    }

    /**
     * Filter the lists of a usage report (block or menu usage).
     *
     * For a restricted caller the hidden entries are taken off `total` and counted in usages_hidden_by_permissions;
     * `used` keeps reporting every usage, so shared content is never mistaken for unused.
     *
     * @param array $report
     * @param array $entityLists Keys of lists naming entities by entity_type and id.
     * @param array $storeLists Keys of lists whose rows carry store_ids (widget instances).
     * @return array
     * @phpstan-param array<string, mixed> $report
     * @phpstan-param list<string> $entityLists
     * @phpstan-param list<string> $storeLists
     * @phpstan-return array<string, mixed>
     */
    public function filterUsageReport(array $report, array $entityLists, array $storeLists = []): array
    {
        if (!$this->isRestricted()) {
            return $report;
        }
        $groups = [];
        foreach ($entityLists as $key) {
            $groups[$key] = $this->listOf($report[$key] ?? []);
        }
        $filtered = $this->filterGrouped($groups);
        $hidden = array_sum($filtered['hidden']);
        foreach ($filtered['visible'] as $key => $items) {
            $report[$key] = $items;
        }
        foreach ($storeLists as $key) {
            $rows = $this->filterByStoreIds($this->listOf($report[$key] ?? []));
            $report[$key] = $rows['visible'];
            $hidden += $rows['hidden'];
        }
        if (is_int($report['total'] ?? null)) {
            $report['total'] -= $hidden;
        }
        return $report + ['usages_hidden_by_permissions' => $hidden];
    }

    /**
     * Add a hidden counter to a result for a restricted caller; unrestricted callers get the result unchanged.
     *
     * @param array $result
     * @param string $key E.g. usages_hidden_by_permissions.
     * @param int $hidden
     * @return array
     * @phpstan-param array<string, mixed> $result
     * @phpstan-return array<string, mixed>
     */
    public function withHidden(array $result, string $key, int $hidden): array
    {
        return $this->isRestricted() ? $result + [$key => $hidden] : $result;
    }

    /**
     * Read the scope data of every entry a restricted caller's lists name (one query per entity type).
     *
     * @param array $lists Entries with the keys of their type and id.
     * @return void
     * @phpstan-param list<array{list<array<string, mixed>>, string, string}> $lists
     */
    private function prefetch(array $lists): void
    {
        $content = [];
        $ids = [self::KIND_PRODUCT => [], self::KIND_CATEGORY => []];
        foreach ($lists as [$items, $typeKey, $idKey]) {
            foreach ($items as $item) {
                $target = $this->target($item, $typeKey, $idKey);
                if ($target === null) {
                    continue;
                }
                if ($target['kind'] === self::KIND_CONTENT) {
                    $content[] = ['entity_type' => $target['entity_type'], 'id' => $target['id']];
                } elseif ($target['kind'] === self::KIND_PRODUCT) {
                    if (!array_key_exists($target['id'], $this->productWebsiteIds)) {
                        $ids[self::KIND_PRODUCT][$target['id']] = $target['id'];
                    }
                } elseif (!array_key_exists($target['id'], $this->categoryPaths)) {
                    $ids[self::KIND_CATEGORY][$target['id']] = $target['id'];
                }
            }
        }
        $this->contentLocator->prefetchScope($content);
        if ($ids[self::KIND_PRODUCT] !== []) {
            $found = $this->catalogScopeReader->productWebsiteIds(array_values($ids[self::KIND_PRODUCT]));
            foreach ($ids[self::KIND_PRODUCT] as $id) {
                $this->productWebsiteIds[$id] = $found[$id] ?? null;
            }
        }
        if ($ids[self::KIND_CATEGORY] !== []) {
            $found = $this->catalogScopeReader->categoryPaths(array_values($ids[self::KIND_CATEGORY]));
            foreach ($ids[self::KIND_CATEGORY] as $id) {
                $this->categoryPaths[$id] = $found[$id] ?? null;
            }
        }
    }

    /**
     * Whether a restricted caller may see the entity an entry names (entries naming none are kept), after prefetch().
     *
     * Content entities, products and categories that no longer exist count as hidden.
     *
     * @param array $item
     * @param string $typeKey
     * @param string $idKey
     * @return bool
     * @phpstan-param array<string, mixed> $item
     */
    private function allowsItem(array $item, string $typeKey, string $idKey): bool
    {
        $target = $this->target($item, $typeKey, $idKey);
        if ($target === null) {
            return true;
        }
        if ($target['kind'] === self::KIND_PRODUCT) {
            $websiteIds = $this->productWebsiteIds[$target['id']] ?? null;
            return $websiteIds !== null && $this->storeScopeGuard->allowsProductWebsites($websiteIds);
        }
        if ($target['kind'] === self::KIND_CATEGORY) {
            $path = $this->categoryPaths[$target['id']] ?? null;
            return $path !== null && $this->storeScopeGuard->allowsCategoryPath($path);
        }
        return $this->contentLocator->allowsRead($target['entity_type'], $target['id']);
    }

    /**
     * Scope target of an entry, or null when it names no content entity, product or category.
     *
     * @param array $item
     * @param string $typeKey
     * @param string $idKey
     * @return array|null
     * @phpstan-param array<string, mixed> $item
     * @phpstan-return array{kind: string, entity_type: string, id: int}|null
     */
    private function target(array $item, string $typeKey, string $idKey): ?array
    {
        $type = $item[$typeKey] ?? null;
        if (!is_string($type)) {
            return null;
        }
        $kind = match (true) {
            in_array($type, ContentLocator::TYPES, true) => self::KIND_CONTENT,
            in_array($type, self::PRODUCT_TYPES, true) => self::KIND_PRODUCT,
            in_array($type, self::CATEGORY_TYPES, true) => self::KIND_CATEGORY,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        return ['kind' => $kind, 'entity_type' => $type, 'id' => Cast::int($item[$idKey] ?? 0)];
    }

    /**
     * Entries of a report list.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }
        return $list;
    }
}
