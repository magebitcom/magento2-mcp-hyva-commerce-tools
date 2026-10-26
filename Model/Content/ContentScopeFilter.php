<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Hides content entities (CMS pages, blocks, menus, widget instances) outside the caller's website/store view
 * restriction from usage lists, audits and widgets, with the read rule of StoreScopeGuard. Other entries (products,
 * categories, media files, configuration) pass unchanged: they are not scoped by store view in Hyvä.
 *
 * Unrestricted callers get their input back without any lookup. Restricted callers cost one store view query per
 * content type and request (ContentLocator::prefetchScope()); entities that no longer exist count as hidden.
 */
class ContentScopeFilter
{
    /**
     * @param ContentLocator $contentLocator
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly StoreScopeGuard $storeScopeGuard
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
     * @param array $items Entries naming a content entity by type and id; other types are kept.
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
        $targets = [];
        foreach ($groups as $items) {
            foreach ($items as $item) {
                $target = $this->target($item, $typeKey, $idKey);
                if ($target !== null) {
                    $targets[] = $target;
                }
            }
        }
        $this->contentLocator->prefetchScope($targets);
        $visible = [];
        foreach ($groups as $key => $items) {
            $visible[$key] = [];
            foreach ($items as $item) {
                $target = $this->target($item, $typeKey, $idKey);
                if ($target === null || $this->contentLocator->allowsRead($target['entity_type'], $target['id'])) {
                    $visible[$key][] = $item;
                } else {
                    $hidden[$key]++;
                }
            }
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
     * Scope target of an entry, or null when it names no content entity.
     *
     * @param array $item
     * @param string $typeKey
     * @param string $idKey
     * @return array|null
     * @phpstan-param array<string, mixed> $item
     * @phpstan-return array{entity_type: string, id: int}|null
     */
    private function target(array $item, string $typeKey, string $idKey): ?array
    {
        $type = $item[$typeKey] ?? null;
        if (!is_string($type) || !in_array($type, ContentLocator::TYPES, true)) {
            return null;
        }
        return ['entity_type' => $type, 'id' => Cast::int($item[$idKey] ?? 0)];
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
