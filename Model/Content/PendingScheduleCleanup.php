<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Throwable;

/**
 * Pending scheduled release items of a page, block or menu that is deleted (T-68, T-166).
 *
 * Hyvä keeps scheduled items in one table keyed by entity_type + entity_id, with no FK or delete observer for
 * them, and an item whose entity is gone can never publish. The delete tools list them in the preview and cancel
 * them after the delete; no schedule-cancel permission is needed, the delete permission covers its own cleanup.
 *
 * @phpstan-type PendingItem array{item_id: int, release_id: int, release_name: string, time_scheduled: string}
 */
class PendingScheduleCleanup
{
    /**
     * @param ScheduleService $scheduleService
     */
    public function __construct(
        private readonly ScheduleService $scheduleService
    ) {
    }

    /**
     * Pending items of pending releases that publish this entity.
     *
     * @param string $type ContentLocator::TYPE_*
     * @param int $entityId
     * @return array
     * @phpstan-return list<PendingItem>
     */
    public function pendingItems(string $type, int $entityId): array
    {
        $items = [];
        foreach ($this->scheduleService->getItems(
            ['status' => 'pending', 'entity_type' => $type, 'entity_id' => $entityId]
        ) as $row) {
            $items[] = [
                'item_id' => $row['item_id'],
                'release_id' => $row['release_id'],
                'release_name' => $row['release_name'],
                'time_scheduled' => $row['time_scheduled'],
            ];
        }
        return $items;
    }

    /**
     * Preview hint about the pending items, empty when there are none.
     *
     * @param string $what E.g. "menu", "page".
     * @param array $items
     * @return string
     * @phpstan-param list<PendingItem> $items
     */
    public function previewHint(string $what, array $items): string
    {
        if ($items === []) {
            return '';
        }
        return sprintf(
            ' The %s has %d pending scheduled release item(s) (details.scheduled_items); deleting cancels them, and '
                . 'a release left without pending items is cancelled as well.',
            $what,
            count($items)
        );
    }

    /**
     * Cancel the deleted entity's pending items; the entity is gone either way, so a failure is reported.
     *
     * @param string $what E.g. "menu", "page", for the warning.
     * @param array $items
     * @return array Reply keys: scheduled_items_cancelled and scheduled_releases_cancelled, or a warning.
     * @phpstan-param list<PendingItem> $items
     * @phpstan-return array<string, mixed>
     */
    public function cancel(string $what, array $items): array
    {
        if ($items === []) {
            return [];
        }
        try {
            $result = $this->scheduleService->cancel(array_column($items, 'item_id'));
        } catch (Throwable $e) {
            return [
                'scheduled_items_cancelled' => [],
                'warning' => sprintf(
                    'The %s was deleted, but its pending scheduled items %s could not be cancelled (%s); cancel '
                        . 'them with hyva_cms_schedule_cancel.',
                    $what,
                    implode(', ', array_map(
                        static fn (int $id): string => '#' . $id,
                        array_column($items, 'item_id')
                    )),
                    $e->getMessage()
                ),
            ];
        }
        return [
            'scheduled_items_cancelled' => $result['cancelled_items'],
            'scheduled_releases_cancelled' => $result['cancelled_releases'],
        ];
    }
}
