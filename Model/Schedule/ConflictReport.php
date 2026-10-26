<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Schedule;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;

/**
 * Loads pending releases/items and the scheduled entities, then runs the conflict detector.
 */
class ConflictReport
{
    /**
     * @param ScheduleService $scheduleService
     * @param ScheduleConflictDetector $detector
     * @param ContentIndex $contentIndex
     * @param ContentScanner $contentScanner
     * @param ContentLocator $contentLocator
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ScheduleConflictDetector $detector,
        private readonly ContentIndex $contentIndex,
        private readonly ContentScanner $contentScanner,
        private readonly ContentLocator $contentLocator
    ) {
    }

    /**
     * Drop conflicts naming a scheduled item of content outside the MCP caller's website/store view restriction.
     *
     * Release-level conflicts and identifier collisions, which carry no single entity, are judged by their items.
     * Unrestricted callers get the conflicts back without any query; restricted ones cost one item query and one
     * store view query per type.
     *
     * @param array $conflicts
     * @return array
     * @phpstan-param list<array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}> $conflicts
     * @phpstan-return list<array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}>
     */
    public function withinCallerScope(array $conflicts): array
    {
        if ($conflicts === [] || !$this->contentLocator->isScopeRestricted()) {
            return $conflicts;
        }
        $items = $this->scheduleService->getItems(['status' => 'pending']);
        $this->contentLocator->prefetchScope(array_map(
            static fn (array $item): array => ['entity_type' => $item['entity_type'], 'id' => $item['entity_id']],
            $items
        ));
        $hidden = [];
        foreach ($items as $item) {
            if (!$this->contentLocator->allowsRead($item['entity_type'], $item['entity_id'])) {
                $hidden[$item['item_id']] = true;
            }
        }
        return array_values(array_filter(
            $conflicts,
            static fn (array $conflict): bool => array_filter(
                $conflict['item_ids'],
                static fn (int $itemId): bool => isset($hidden[$itemId])
            ) === []
        ));
    }

    /**
     * Get conflicts.
     *
     * @param int $windowMinutes
     * @param string|null $entityType Only conflicts touching this entity.
     * @param int|null $entityId
     * @return array
     * @phpstan-return list<array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}>
     */
    public function getConflicts(int $windowMinutes = 60, ?string $entityType = null, ?int $entityId = null): array
    {
        $items = [];
        foreach ($this->scheduleService->getItems(['status' => 'pending']) as $row) {
            $items[] = [
                'item_id' => $row['item_id'],
                'release_id' => $row['release_id'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'name' => $row['name'],
            ];
        }
        $conflicts = $this->detector->detect(
            $items,
            $this->scheduleService->getReleases('pending'),
            $this->getEntities($items),
            time(),
            $windowMinutes
        );
        if ($entityType === null) {
            return $conflicts;
        }
        return array_values(array_filter($conflicts, static fn (array $c): bool => !array_key_exists('entity_type', $c)
            || ($c['entity_type'] === $entityType && ($entityId === null || ($c['entity_id'] ?? null) === $entityId))));
    }

    /**
     * Get entities.
     *
     * @param array $items
     * @return array
     * @phpstan-param list<array{item_id: int, release_id: int, entity_type: string, entity_id: int,
     *     name: string}> $items
     * @phpstan-return array<string, array{exists: bool, identifier?: string, store_ids?: list<int>}>
     */
    private function getEntities(array $items): array
    {
        $known = [];
        foreach ([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK] as $type) {
            if (array_filter($items, static fn (array $i): bool => $i['entity_type'] === $type) === []) {
                continue;
            }
            foreach ($this->contentIndex->getRows($type) as $row) {
                $known[$type . ':' . $row['id']] = [
                    'exists' => true,
                    'identifier' => $row['identifier'],
                    'store_ids' => $row['store_ids'],
                ];
            }
        }
        foreach ($this->contentScanner->getMenuRows() as $row) {
            $known['menu:' . Cast::int($row['id'])] = [
                'exists' => true,
                'identifier' => Cast::string($row['identifier']),
                'store_ids' => [],
            ];
        }
        $entities = [];
        foreach ($items as $item) {
            $key = $item['entity_type'] . ':' . $item['entity_id'];
            $entities[$key] = $known[$key] ?? ['exists' => false];
        }
        return $entities;
    }
}
