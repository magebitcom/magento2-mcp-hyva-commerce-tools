<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Schedule;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;

/**
 * Loads pending releases/items and the scheduled entities, then runs the conflict detector.
 *
 * @phpstan-type Item array{item_id: int, release_id: int, entity_type: string, entity_id: int, name: string}
 * @phpstan-type Entity array{exists: bool, identifier?: string, store_ids?: list<int>}
 * @phpstan-type Conflict array{severity: string, code: string, message: string, entity_type?: string,
 *     entity_id?: int, entity_ids?: list<int>, release_ids: list<int>, item_ids: list<int>}
 */
class ConflictReport
{
    /**
     * @param ScheduleService $scheduleService
     * @param ScheduleConflictDetector $detector
     * @param ContentIndex $contentIndex
     * @param FeatureCheckerInterface $featureChecker
     * @param ContentLocator $contentLocator
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ScheduleConflictDetector $detector,
        private readonly ContentIndex $contentIndex,
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly ContentLocator $contentLocator,
        private readonly StoreScopeGuard $storeScopeGuard
    ) {
    }

    /**
     * Get conflicts.
     *
     * @param int $windowMinutes
     * @param string|null $entityType Only conflicts touching this entity.
     * @param int|null $entityId
     * @return array
     * @phpstan-return list<Conflict>
     */
    public function getConflicts(int $windowMinutes = 60, ?string $entityType = null, ?int $entityId = null): array
    {
        $items = $this->pendingItems();
        return $this->detect($items, $this->getEntities($items), $windowMinutes, $entityType, $entityId);
    }

    /**
     * Conflicts, without those naming a scheduled item of content outside the MCP caller's website/store view
     * restriction or of a content type outside $allowedTypes, and how many were dropped.
     *
     * Identifier collisions are judged by their items. Release-level conflicts (overdue release) name no item: they
     * are judged by the release's pending items and kept when one of them is visible, as `hyva_cms.schedule.list`
     * shows a release; a release without pending items names no content and is kept. The scope check reuses the items
     * and the page/block store views the detection read; only menu items cost one more store view query, and only for
     * a restricted caller.
     *
     * @param int $windowMinutes
     * @param string|null $entityType Only conflicts touching this entity.
     * @param int|null $entityId
     * @param array|null $allowedTypes Content types the caller may see items of; null for all.
     * @return array
     * @phpstan-param list<string>|null $allowedTypes
     * @phpstan-return array{conflicts: list<Conflict>, hidden: int}
     */
    public function getConflictsWithinCallerScope(
        int $windowMinutes = 60,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $allowedTypes = null
    ): array {
        $items = $this->pendingItems();
        $entities = $this->getEntities($items);
        $conflicts = $this->detect($items, $entities, $windowMinutes, $entityType, $entityId);
        $restricted = $this->contentLocator->isScopeRestricted();
        if ($conflicts === [] || (!$restricted && $allowedTypes === null)) {
            return ['conflicts' => $conflicts, 'hidden' => 0];
        }
        if ($restricted) {
            $menus = array_values(array_filter(
                $items,
                static fn (array $item): bool => $item['entity_type'] === ContentLocator::TYPE_MENU
            ));
            $this->contentLocator->prefetchScope(array_map(
                static fn (array $item): array => ['entity_type' => $item['entity_type'], 'id' => $item['entity_id']],
                $menus
            ));
        }
        $hidden = [];
        $releaseItems = [];
        foreach ($items as $item) {
            $releaseItems[$item['release_id']][] = $item['item_id'];
            if ($allowedTypes !== null && !in_array($item['entity_type'], $allowedTypes, true)) {
                $hidden[$item['item_id']] = true;
                continue;
            }
            if (!$restricted) {
                continue;
            }
            $entity = $entities[$item['entity_type'] . ':' . $item['entity_id']] ?? ['exists' => false];
            // Menus come without store views here (the detector treats them as every store view).
            $readable = $item['entity_type'] === ContentLocator::TYPE_MENU
                ? $this->contentLocator->allowsRead($item['entity_type'], $item['entity_id'])
                : $entity['exists'] && $this->storeScopeGuard->allowsRead($entity['store_ids'] ?? []);
            if (!$readable) {
                $hidden[$item['item_id']] = true;
            }
        }
        $visible = [];
        foreach ($conflicts as $conflict) {
            if ($conflict['item_ids'] !== []) {
                $keep = array_filter(
                    $conflict['item_ids'],
                    static fn (int $itemId): bool => isset($hidden[$itemId])
                ) === [];
            } else {
                $ofReleases = [];
                foreach ($conflict['release_ids'] as $releaseId) {
                    $ofReleases = array_merge($ofReleases, $releaseItems[$releaseId] ?? []);
                }
                $keep = $ofReleases === []
                    || array_filter($ofReleases, static fn (int $itemId): bool => !isset($hidden[$itemId])) !== [];
            }
            if ($keep) {
                $visible[] = $conflict;
            }
        }
        return ['conflicts' => $visible, 'hidden' => count($conflicts) - count($visible)];
    }

    /**
     * Pending schedule items.
     *
     * @return array
     * @phpstan-return list<Item>
     */
    private function pendingItems(): array
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
        return $items;
    }

    /**
     * Run the detector, optionally keeping only conflicts touching one entity.
     *
     * @param array $items
     * @param array $entities
     * @param int $windowMinutes
     * @param string|null $entityType
     * @param int|null $entityId
     * @return array
     * @phpstan-param list<Item> $items
     * @phpstan-param array<string, Entity> $entities
     * @phpstan-return list<Conflict>
     */
    private function detect(
        array $items,
        array $entities,
        int $windowMinutes,
        ?string $entityType,
        ?int $entityId
    ): array {
        $conflicts = $this->detector->detect(
            $items,
            $this->scheduleService->getReleases('pending'),
            $entities,
            time(),
            $windowMinutes
        );
        if ($entityType === null) {
            return $conflicts;
        }
        return array_values(array_filter($conflicts, static fn (array $c): bool => !array_key_exists('entity_type', $c)
            || ($c['entity_type'] === $entityType && ($entityId === null
                || in_array($entityId, $c['entity_ids'] ?? [$c['entity_id'] ?? null], true)))));
    }

    /**
     * Get entities.
     *
     * @param array $items
     * @return array
     * @phpstan-param list<Item> $items
     * @phpstan-return array<string, Entity>
     */
    private function getEntities(array $items): array
    {
        $known = [];
        // Only the scheduled entities are read, and without their content columns.
        foreach ([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK, ContentLocator::TYPE_MENU] as $type) {
            $ids = array_values(array_unique(array_map(
                static fn (array $i): int => $i['entity_id'],
                array_filter($items, static fn (array $i): bool => $i['entity_type'] === $type)
            )));
            if ($ids === []
                || ($type === ContentLocator::TYPE_MENU
                    && !$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER))) {
                continue;
            }
            foreach ($this->contentIndex->getIdentifiersByIds($type, $ids) as $id => $row) {
                $known[$type . ':' . $id] = [
                    'exists' => true,
                    'identifier' => $row['identifier'],
                    'store_ids' => $row['store_ids'],
                ];
            }
        }
        $entities = [];
        foreach ($items as $item) {
            $key = $item['entity_type'] . ':' . $item['entity_id'];
            $entities[$key] = $known[$key] ?? ['exists' => false];
        }
        return $entities;
    }
}
