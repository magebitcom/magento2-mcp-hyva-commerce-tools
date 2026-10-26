<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Hyva\CmsLiveviewEditor\Model\Provider as ContentProvider;
use Hyva\CmsScheduling\Api\Data\ScheduledItemInterface;
use Hyva\CmsScheduling\Api\Data\ScheduledItemInterfaceFactory;
use Hyva\CmsScheduling\Api\Data\ScheduledReleaseInterface;
use Hyva\CmsScheduling\Api\Data\ScheduledReleaseInterfaceFactory;
use Hyva\CmsScheduling\Api\ScheduledItemRepositoryInterface;
use Hyva\CmsScheduling\Api\ScheduledReleaseRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Rakit\Validation\Validator;

/**
 * Hyvä CMS scheduled releases without an admin session.
 *
 * Hyvä's own ScheduleItemsForPublication / Cancel* services read the admin session user, which does not
 * exist for MCP calls, so creation and cancellation follow the same rules here through the repositories.
 *
 * @phpstan-type ScheduleItemRow array{item_id: int, release_id: int, entity_type: string, entity_id: int, name: string,
 *     status: string, settings: string|null, version_history_id: string|null, creation_time: string,
 *     release_name: string, release_status: string, time_scheduled: string}
 */
class ScheduleService
{
    public const MAX_ITEMS_PER_RELEASE = 100;
    private const RELEASE_TABLE = 'hyva_commerce_cms_scheduled_release';
    private const ITEM_TABLE = 'hyva_commerce_cms_scheduled_item';
    private const TYPE_LABELS = [
        ContentLocator::TYPE_PAGE => 'CMS Page',
        ContentLocator::TYPE_BLOCK => 'CMS Block',
        ContentLocator::TYPE_MENU => 'Menu',
    ];

    /**
     * @param ResourceConnection $resourceConnection
     * @param ScheduledReleaseRepositoryInterface $releaseRepository
     * @param ScheduledItemRepositoryInterface $itemRepository
     * @param ScheduledReleaseInterfaceFactory $releaseFactory
     * @param ScheduledItemInterfaceFactory $itemFactory
     * @param ContentProvider $contentProvider
     * @param ContentService $contentService
     * @param Validator $validator
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScheduledReleaseRepositoryInterface $releaseRepository,
        private readonly ScheduledItemRepositoryInterface $itemRepository,
        private readonly ScheduledReleaseInterfaceFactory $releaseFactory,
        private readonly ScheduledItemInterfaceFactory $itemFactory,
        private readonly ContentProvider $contentProvider,
        private readonly ContentService $contentService,
        private readonly Validator $validator
    ) {
    }

    /**
     * Entity ids of a type that have a pending item in a pending release.
     *
     * @param string $type
     * @return array
     * @phpstan-return list<int>
     */
    public function getPendingEntityIds(string $type): array
    {
        $ids = [];
        foreach ($this->getItems(['status' => 'pending', 'entity_type' => $type]) as $item) {
            $ids[] = $item['entity_id'];
        }
        return array_values(array_unique($ids));
    }

    /**
     * Scheduled items joined with their release.
     *
     * @param array $filters `status` = pending (item + release pending), any, or an exact item status;
     *     `from` / `to` are UTC 'Y-m-d H:i:s'; `item_ids` limits to these items; `limit` keeps only the newest
     *     N rows (by release time, still returned oldest first).
     * @return array
     * @phpstan-param array{status?: string|null, entity_type?: string|null, entity_id?: int|null, from?: string|null,
     *     to?: string|null, release_id?: int|null, item_ids?: list<int>, limit?: int|null} $filters
     * @phpstan-return list<ScheduleItemRow>
     */
    public function getItems(array $filters = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['i' => $this->resourceConnection->getTableName(self::ITEM_TABLE)], [
                'item_id' => 'id', 'release_id', 'entity_type', 'entity_id', 'name', 'status', 'settings',
                'version_history_id', 'creation_time',
            ])
            ->join(
                ['r' => $this->resourceConnection->getTableName(self::RELEASE_TABLE)],
                'r.id = i.release_id',
                ['release_name' => 'name', 'release_status' => 'status', 'time_scheduled']
            );
        $limit = $filters['limit'] ?? null;
        if ($limit !== null && $limit > 0) {
            $select->order(['r.time_scheduled DESC', 'i.id DESC'])->limit($limit);
        } else {
            $limit = null;
            $select->order(['r.time_scheduled ASC', 'i.id ASC']);
        }

        $status = $filters['status'] ?? 'pending';
        if ($status === 'pending') {
            $select->where('i.status = ?', 'pending')->where('r.status = ?', 'pending');
        } elseif ($status !== null && $status !== 'any') {
            $select->where('i.status = ?', $status);
        }
        if (!empty($filters['entity_type'])) {
            $select->where('i.entity_type = ?', $filters['entity_type']);
        }
        if (!empty($filters['entity_id'])) {
            $select->where('i.entity_id = ?', $filters['entity_id']);
        }
        if (!empty($filters['release_id'])) {
            $select->where('i.release_id = ?', $filters['release_id']);
        }
        if (isset($filters['item_ids'])) {
            $select->where('i.id IN (?)', $filters['item_ids'] === [] ? [0] : $filters['item_ids']);
        }
        if (!empty($filters['from'])) {
            $select->where('r.time_scheduled >= ?', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $select->where('r.time_scheduled <= ?', $filters['to']);
        }

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'item_id' => Cast::int($row['item_id']),
                'release_id' => Cast::int($row['release_id']),
                'entity_type' => Cast::string($row['entity_type']),
                'entity_id' => Cast::int($row['entity_id']),
                'name' => Cast::string($row['name']),
                'status' => Cast::string($row['status']),
                'settings' => Cast::nullableString($row['settings']),
                'version_history_id' => Cast::nullableString($row['version_history_id']),
                'creation_time' => Cast::string($row['creation_time']),
                'release_name' => Cast::string($row['release_name']),
                'release_status' => Cast::string($row['release_status']),
                'time_scheduled' => Cast::string($row['time_scheduled']),
            ];
        }
        return $limit === null ? $rows : array_reverse($rows);
    }

    /**
     * Get releases.
     *
     * @param string|null $status pending|any|<exact release status>
     * @return array
     * @phpstan-return array<int, array{release_id: int, name: string, time_scheduled: string, status: string}>
     */
    public function getReleases(?string $status = 'pending'): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::RELEASE_TABLE),
                ['id', 'name', 'time_scheduled', 'status']
            )
            ->order('time_scheduled ASC');
        if ($status !== null && $status !== 'any') {
            $select->where('status = ?', $status);
        }
        $releases = [];
        foreach ($connection->fetchAll($select) as $row) {
            $releases[Cast::int($row['id'])] = [
                'release_id' => Cast::int($row['id']),
                'name' => Cast::string($row['name']),
                'time_scheduled' => Cast::string($row['time_scheduled']),
                'status' => Cast::string($row['status']),
            ];
        }
        return $releases;
    }

    /**
     * Schedule the entity's current draft (or a version-history item) for publication.
     *
     * @param string $type
     * @param int $entityId
     * @param string $entityName
     * @param array $release
     * @param int|null $versionId Schedule this version instead of the current draft.
     * @param array|null $settings Entity attributes applied at publish time (e.g. is_active).
     * @return array
     * @throws LocalizedException
     * @phpstan-param array{release_id?: int|null, release_name?: string|null, time_utc?: string|null} $release
     * @phpstan-param array<string, mixed>|null $settings
     * @phpstan-return array{release_id: int, item_id: int, release_created: bool, reactivated: bool}
     */
    public function schedule(
        string $type,
        int $entityId,
        string $entityName,
        array $release,
        ?int $versionId = null,
        ?array $settings = null
    ): array {
        if (!$this->contentProvider->isSchedulingAllowed($type)) {
            throw new LocalizedException(__('Scheduling is not available for %1.', $type));
        }

        if ($versionId !== null) {
            $document = json_decode($this->contentService->getVersionData($type, $entityId, $versionId), true);
        } else {
            $document = $this->contentService->getWorkingDocument($type, $entityId);
        }
        if (!is_array($document) || empty($document['content'])) {
            throw new LocalizedException(__('%1 #%2 has no content to schedule.', $type, $entityId));
        }
        $stringKeyed = [];
        foreach ($document as $key => $value) {
            $stringKeyed[(string) $key] = $value;
        }
        $stringKeyed = $this->contentService->stamp($stringKeyed, 'draft');
        $content = json_encode($stringKeyed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($content === false) {
            throw new LocalizedException(__('Could not encode the content to schedule.'));
        }

        $settingsJson = null;
        if ($settings !== null && $settings !== []) {
            $this->validateSettings($type, $settings);
            $settingsJson = (string) json_encode($settings);
        }

        $releaseCreated = false;
        $releaseId = $release['release_id'] ?? null;
        if ($releaseId !== null) {
            $releaseModel = $this->loadRelease($releaseId);
            if ($releaseModel->getStatus() !== ScheduledReleaseInterface::STATUS_PENDING) {
                throw new LocalizedException(
                    __(
                        'Release #%1 is %2; only pending releases accept items.',
                        $releaseId,
                        (string) $releaseModel->getStatus()
                    )
                );
            }
        } else {
            $releaseModel = $this->createRelease(
                (string) ($release['release_name'] ?? ''),
                (string) ($release['time_utc'] ?? '')
            );
            $releaseCreated = true;
        }
        $releaseId = $this->modelId($releaseModel);

        try {
            [$item, $reactivated] = $this->saveItem($releaseId, $type, $entityId, $entityName, $content, $settingsJson);
        } catch (LocalizedException $e) {
            if ($releaseCreated) {
                $this->releaseRepository->delete($releaseModel);
            }
            throw $e;
        }

        $itemIds = json_decode((string) $releaseModel->getScheduledItems(), true);
        $itemIds = is_array($itemIds) ? $itemIds : [];
        $itemIds[] = $this->modelId($item);
        $releaseModel->setScheduledItems((string) json_encode(array_values(array_unique($itemIds))));
        $releaseModel->setRevisionLog(
            $this->appendLog($releaseModel->getRevisionLog(), 'item_added', ['item_id' => $this->modelId($item)])
        );
        $this->releaseRepository->save($releaseModel);

        return [
            'release_id' => $releaseId,
            'item_id' => $this->modelId($item),
            'release_created' => $releaseCreated,
            'reactivated' => $reactivated,
        ];
    }

    /**
     * Cancel scheduled items or a whole release. Content and version history are untouched.
     *
     * @param array $itemIds
     * @param int|null $releaseId Cancels the release and all of its pending items.
     * @param callable|null $mayCancel fn(string $entityType, int $entityId): bool; checked for every item before
     *     anything changes.
     * @return array
     * @throws LocalizedException
     * @phpstan-param list<int> $itemIds
     * @phpstan-param (callable(string, int): bool)|null $mayCancel
     * @phpstan-return array{cancelled_items: list<int>, cancelled_releases: list<int>}
     */
    public function cancel(array $itemIds, ?int $releaseId = null, ?callable $mayCancel = null): array
    {
        $cancelledItems = [];
        $cancelledReleases = [];
        $touchedReleases = [];

        if ($releaseId !== null) {
            foreach ($this->getItems(
                ['status' => ScheduledItemInterface::STATUS_PENDING, 'release_id' => $releaseId]
            ) as $row) {
                $itemIds[] = $row['item_id'];
            }
            $touchedReleases[$releaseId] = true;
        }

        $items = [];
        $refused = [];
        foreach (array_unique($itemIds) as $itemId) {
            try {
                $items[$itemId] = $this->itemRepository->get($itemId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('Scheduled item #%1 does not exist.', $itemId));
            }
            $type = (string) $items[$itemId]->getEntityType();
            if ($mayCancel !== null && !$mayCancel($type, (int) $items[$itemId]->getEntityId())) {
                $refused[] = sprintf('#%d (%s)', $itemId, $type);
            }
        }
        if ($refused !== []) {
            throw new LocalizedException(__(
                'Nothing was cancelled: your role may not cancel scheduled %1.',
                implode(', ', $refused)
            ));
        }

        foreach ($items as $itemId => $item) {
            if ($item->getStatus() !== ScheduledItemInterface::STATUS_PENDING) {
                continue;
            }
            $item->setStatus(ScheduledItemInterface::STATUS_CANCELLED);
            $item->setRevisionLog($this->appendLog($item->getRevisionLog(), 'cancelled'));
            $this->itemRepository->save($item);
            $cancelledItems[] = (int) $itemId;
            $touchedReleases[Cast::int($item->getReleaseId())] = true;
        }

        foreach (array_keys($touchedReleases) as $touchedId) {
            $release = $this->loadRelease($touchedId);
            $stillPending = $this->getItems(
                ['status' => ScheduledItemInterface::STATUS_PENDING, 'release_id' => $touchedId]
            );
            if ($release->getStatus() === ScheduledReleaseInterface::STATUS_PENDING && $stillPending === []) {
                $release->setStatus(ScheduledReleaseInterface::STATUS_CANCELLED);
                $release->setRevisionLog($this->appendLog($release->getRevisionLog(), 'cancelled'));
                $this->releaseRepository->save($release);
                $cancelledReleases[] = $touchedId;
            }
        }

        return ['cancelled_items' => $cancelledItems, 'cancelled_releases' => $cancelledReleases];
    }

    /**
     * Check schedule settings against the entity type's Hyvä rules now, not when the cron publishes.
     *
     * Only keys with a rule are accepted, and the full rule set is applied (e.g. a page needs is_active and title).
     * This is deliberately stricter than Hyvä, whose admin form validates only the keys sent: the settings replace
     * the entity attributes at publish time, so an incomplete set is rejected up front.
     *
     * @param string $type
     * @param array $settings
     * @return void
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $settings
     */
    private function validateSettings(string $type, array $settings): void
    {
        $rules = $this->contentProvider->getScheduleValidationRules($type);
        $unknown = array_diff(array_keys($settings), array_keys($rules));
        if ($unknown !== []) {
            throw new LocalizedException(__(
                'Settings %1 cannot be scheduled for %2; allowed: %3.',
                implode(', ', $unknown),
                $type,
                implode(', ', array_keys($rules))
            ));
        }
        $validation = $this->validator->validate($settings, $rules);
        if ($validation->fails()) {
            throw new LocalizedException(__('Invalid settings: %1', implode(', ', $validation->errors()->all())));
        }
    }

    /**
     * Create release.
     *
     * @param string $name
     * @param string $timeUtc
     * @return ScheduledReleaseInterface
     * @throws LocalizedException
     */
    private function createRelease(string $name, string $timeUtc): ScheduledReleaseInterface
    {
        if (trim($name) === '') {
            throw new LocalizedException(__('A release name is required when no release_id is given.'));
        }
        $time = strtotime($timeUtc . ' UTC');
        if ($time === false) {
            throw new LocalizedException(__('A valid scheduled time is required when no release_id is given.'));
        }
        if ($time <= time()) {
            throw new LocalizedException(__('The scheduled time must be in the future.'));
        }
        $normalized = gmdate('Y-m-d H:i:s', $time);
        if (array_filter(
            $this->getReleases(),
            static fn (array $r): bool => $r['time_scheduled'] === $normalized
        ) !== []
        ) {
            throw new LocalizedException(
                __(
                    'Another pending release is already scheduled at %1 UTC. Add the item to that release '
                        . '(release_id) or pick another time.',
                    $normalized
                )
            );
        }

        $release = $this->releaseFactory->create();
        $release->setName($name);
        $release->setTimeScheduled($normalized);
        $release->setStatus(ScheduledReleaseInterface::STATUS_PENDING);
        $release->setScheduledItems('[]');
        $release->setRevisionLog($this->appendLog('[]', 'created'));
        return $this->releaseRepository->save($release);
    }

    /**
     * Save item.
     *
     * @param int $releaseId
     * @param string $type
     * @param int $entityId
     * @param string $name
     * @param string $content
     * @param string|null $settings
     * @return array
     * @throws LocalizedException
     * @phpstan-return array{0: ScheduledItemInterface, 1: bool}
     */
    private function saveItem(
        int $releaseId,
        string $type,
        int $entityId,
        string $name,
        string $content,
        ?string $settings
    ): array {
        $existing = $this->getItems(
            ['status' => 'any', 'release_id' => $releaseId, 'entity_type' => $type, 'entity_id' => $entityId]
        );
        $reactivated = false;
        if ($existing !== []) {
            $item = $this->itemRepository->get($existing[0]['item_id']);
            if ($item->getStatus() !== ScheduledItemInterface::STATUS_CANCELLED) {
                throw new LocalizedException(
                    __('%1 #%2 is already scheduled in release #%3.', $type, $entityId, $releaseId)
                );
            }
            $reactivated = true;
        } else {
            $pending = $this->getItems(
                ['status' => ScheduledItemInterface::STATUS_PENDING, 'release_id' => $releaseId]
            );
            if (count($pending) >= self::MAX_ITEMS_PER_RELEASE) {
                throw new LocalizedException(
                    __('Release #%1 already holds %2 items.', $releaseId, self::MAX_ITEMS_PER_RELEASE)
                );
            }
            $item = $this->itemFactory->create();
            $item->setReleaseId($releaseId);
            $item->setScheduledEntityId($entityId);
            $item->setEntityType($type);
            $item->setEntityTypeLabel(self::TYPE_LABELS[$type] ?? $type);
        }
        $item->setName($name);
        $item->setContent($content);
        $item->setSettings($settings);
        $item->setTailwindCss(null);
        $item->setStatus(ScheduledItemInterface::STATUS_PENDING);
        $item->setRevisionLog($this->appendLog($item->getRevisionLog(), $reactivated ? 'reactivated' : 'created'));
        return [$this->itemRepository->save($item), $reactivated];
    }

    /**
     * Load release.
     *
     * @param int $releaseId
     * @return ScheduledReleaseInterface
     * @throws LocalizedException
     */
    private function loadRelease(int $releaseId): ScheduledReleaseInterface
    {
        try {
            return $this->releaseRepository->get($releaseId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Release #%1 does not exist.', $releaseId));
        }
    }

    /**
     * Model id.
     *
     * @param object $model
     * @return int
     */
    private function modelId(object $model): int
    {
        return $model instanceof AbstractModel ? Cast::int($model->getId()) : 0;
    }

    /**
     * Newest-first revision log, same entry shape as Hyvä's.
     *
     * @param string|null $log
     * @param string $action
     * @param array $extra
     * @return string
     * @phpstan-param array<string, mixed> $extra
     */
    private function appendLog(?string $log, string $action, array $extra = []): string
    {
        $entries = json_decode((string) $log, true);
        $entries = is_array($entries) ? $entries : [];
        array_unshift(
            $entries,
            [
                'action' => $action,
                'time' => date('Y-m-d\TH:i:sP'),
                'user' => $this->contentService->getAuthor(),
            ] + $extra
        );
        return (string) json_encode($entries);
    }
}
