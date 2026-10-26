<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Schedule;

/**
 * Finds problems in pending Hyvä CMS scheduled releases. Pure logic: callers pass plain rows.
 *
 * Hyvä releases are one-way points in time (no end date, no automatic revert), so:
 * - the same entity in two pending releases means the later one silently overwrites the earlier one;
 * - a campaign has a "fallback" only when a later pending release schedules the same entity again.
 */
class ScheduleConflictDetector
{
    public const OVERDUE_GRACE_SECONDS = 900;

    /**
     * Detect.
     *
     * @param array $items Pending items.
     * @param array $releases Pending releases keyed by id (time in UTC).
     * @param array $entities Keyed "type:id".
     * @param int $now Unix time.
     * @param int $windowMinutes Two releases closer than this are treated as simultaneous.
     * @return array
     * @phpstan-param list<array{item_id: int, release_id: int, entity_type: string, entity_id: int,
     *     name?: string}> $items
     * @phpstan-param array<int, array{release_id: int, name: string, time_scheduled: string, status: string}> $releases
     * @phpstan-param array<string, array{exists: bool, identifier?: string, store_ids?: list<int>}> $entities
     * @phpstan-return list<array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}>
     */
    public function detect(array $items, array $releases, array $entities, int $now, int $windowMinutes = 60): array
    {
        $conflicts = [];

        foreach ($releases as $releaseId => $release) {
            $time = strtotime($release['time_scheduled'] . ' UTC');
            if ($time !== false && $time < $now - self::OVERDUE_GRACE_SECONDS) {
                $conflicts[] = $this->conflict('error', 'overdue', sprintf(
                    'Release "%s" was due at %s UTC but is still pending; check the hyva_cms_scheduling_publish cron.',
                    $release['name'],
                    $release['time_scheduled']
                ), [$releaseId], []);
            }
            $hasItems = array_filter($items, static fn (array $item): bool => $item['release_id'] === $releaseId);
            if ($hasItems === []) {
                $conflicts[] = $this->conflict(
                    'warning',
                    'empty_release',
                    sprintf('Release "%s" has no pending items.', $release['name']),
                    [$releaseId],
                    []
                );
            }
        }

        $byEntity = [];
        foreach ($items as $item) {
            if (!isset($releases[$item['release_id']])) {
                continue;
            }
            $byEntity[$item['entity_type'] . ':' . $item['entity_id']][] = $item;
        }

        foreach ($byEntity as $key => $entityItems) {
            $first = $entityItems[0];
            $entity = $entities[$key] ?? ['exists' => true];
            if (!$entity['exists']) {
                $conflicts[] = $this->conflict('error', 'missing_entity', sprintf(
                    '%s #%d is scheduled but no longer exists.',
                    $first['entity_type'],
                    $first['entity_id']
                ), array_column($entityItems, 'release_id'), array_column($entityItems, 'item_id'), $first);
            }

            usort(
                $entityItems,
                fn (array $a, array $b): int => $this->time($releases, $a) <=> $this->time($releases, $b)
            );
            $count = count($entityItems);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $gap = abs($this->time($releases, $entityItems[$j]) - $this->time($releases, $entityItems[$i]));
                    $close = $gap <= $windowMinutes * 60;
                    $conflicts[] = $this->conflict(
                        $close ? 'error' : 'warning',
                        $close ? 'overlapping_schedule' : 'superseded_schedule',
                        sprintf(
                            '%s #%d is in releases "%s" and "%s" %s; the later release overwrites the earlier one.',
                            $first['entity_type'],
                            $first['entity_id'],
                            $releases[$entityItems[$i]['release_id']]['name'],
                            $releases[$entityItems[$j]['release_id']]['name'],
                            $close ? sprintf(
                                'within %d minutes of each other',
                                $windowMinutes
                            ) : sprintf('%d hours apart', (int) round($gap / 3600))
                        ),
                        [$entityItems[$i]['release_id'], $entityItems[$j]['release_id']],
                        [$entityItems[$i]['item_id'], $entityItems[$j]['item_id']],
                        $first
                    );
                }
            }

            $last = $entityItems[$count - 1];
            $conflicts[] = $this->conflict('info', 'no_fallback', sprintf(
                'Nothing is scheduled after release "%s" for %s #%d, so its content stays live until someone '
                    . 'publishes again (Hyvä releases have no end date). The current live version remains in version '
                    . 'history.',
                $releases[$last['release_id']]['name'],
                $first['entity_type'],
                $first['entity_id']
            ), [$last['release_id']], [$last['item_id']], $first);
        }

        $conflicts = array_merge($conflicts, $this->storeCollisions($byEntity, $releases, $entities, $windowMinutes));

        $rank = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($conflicts, static fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $conflicts;
    }

    /**
     * Find different entities sharing an identifier on overlapping store views, scheduled close together.
     *
     * Example: two "home" pages for the same store view.
     *
     * @param array $byEntity
     * @param array $releases
     * @param array $entities
     * @param int $windowMinutes
     * @return array
     * @phpstan-param array<string, list<array{item_id: int, release_id: int, entity_type: string, entity_id: int,
     *     name?: string}>> $byEntity
     * @phpstan-param array<int, array{release_id: int, name: string, time_scheduled: string, status: string}> $releases
     * @phpstan-param array<string, array{exists: bool, identifier?: string, store_ids?: list<int>}> $entities
     * @phpstan-return list<array{severity: string, code: string, message: string, entity_type?: string,
     *     entity_id?: int, release_ids: list<int>, item_ids: list<int>}>
     */
    private function storeCollisions(array $byEntity, array $releases, array $entities, int $windowMinutes): array
    {
        $conflicts = [];
        $keys = array_keys($byEntity);
        $count = count($keys);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $entities[$keys[$i]] ?? null;
                $b = $entities[$keys[$j]] ?? null;
                $itemA = $byEntity[$keys[$i]][0];
                $itemB = $byEntity[$keys[$j]][0];
                if ($a === null || $b === null || $itemA['entity_type'] !== $itemB['entity_type']
                    || ($a['identifier'] ?? '') === '' || ($a['identifier'] ?? '') !== ($b['identifier'] ?? '')) {
                    continue;
                }
                $storesA = $a['store_ids'] ?? [];
                $storesB = $b['store_ids'] ?? [];
                $shared = in_array(0, $storesA, true)
                    || in_array(0, $storesB, true)
                    || array_intersect($storesA, $storesB) !== [];
                $gap = abs($this->time($releases, $itemA) - $this->time($releases, $itemB));
                if ($shared && $gap <= $windowMinutes * 60) {
                    $conflicts[] = $this->conflict('error', 'store_view_collision', sprintf(
                        '%s #%d and #%d share identifier "%s" on overlapping store views and are scheduled within %d '
                            . 'minutes.',
                        $itemA['entity_type'],
                        $itemA['entity_id'],
                        $itemB['entity_id'],
                        (string) ($a['identifier'] ?? ''),
                        $windowMinutes
                    ), [$itemA['release_id'], $itemB['release_id']], [$itemA['item_id'], $itemB['item_id']]);
                }
            }
        }
        return $conflicts;
    }

    /**
     * Time.
     *
     * @param array $releases
     * @param array $item
     * @return int
     * @phpstan-param array<int, array{release_id: int, name: string, time_scheduled: string, status: string}> $releases
     * @phpstan-param array{release_id: int} $item
     */
    private function time(array $releases, array $item): int
    {
        $time = strtotime(($releases[$item['release_id']]['time_scheduled'] ?? '') . ' UTC');
        return $time === false ? 0 : $time;
    }

    /**
     * Conflict.
     *
     * @param string $severity
     * @param string $code
     * @param string $message
     * @param array $releaseIds
     * @param array $itemIds
     * @param array|null $item
     * @return array
     * @phpstan-param list<int> $releaseIds
     * @phpstan-param list<int> $itemIds
     * @phpstan-param array{entity_type: string, entity_id: int}|null $item
     * @phpstan-return array{severity: string, code: string, message: string, entity_type?: string, entity_id?: int,
     *     release_ids: list<int>, item_ids: list<int>}
     */
    private function conflict(
        string $severity,
        string $code,
        string $message,
        array $releaseIds,
        array $itemIds,
        ?array $item = null
    ): array {
        $conflict = ['severity' => $severity, 'code' => $code, 'message' => $message];
        if ($item !== null) {
            $conflict['entity_type'] = $item['entity_type'];
            $conflict['entity_id'] = $item['entity_id'];
        }
        $conflict['release_ids'] = array_values(array_unique($releaseIds));
        $conflict['item_ids'] = array_values(array_unique($itemIds));
        return $conflict;
    }
}
