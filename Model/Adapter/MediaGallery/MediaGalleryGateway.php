<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\MediaGallery;

use Magebit\Mcp\Api\LoggerInterface;
use Hyva\MediaGallery\Model\Asset\SearchAssetsList;
use Hyva\MediaGallery\Model\MediaMover;
use Hyva\MediaGallery\Model\Trash\TrashRepository;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\MediaGalleryApi\Api\CreateDirectoriesByPathsInterface;
use Magento\MediaGalleryApi\Api\Data\AssetInterface;
use Magento\MediaGalleryApi\Api\GetAssetsByIdsInterface;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;
use Magento\MediaGalleryApi\Api\GetAssetsKeywordsInterface;
use Magento\MediaGalleryApi\Api\IsPathExcludedInterface;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Throwable;

/**
 * Hyvä Media Gallery adapter. Hyvä's services check no permissions; the calling tools do.
 *
 * @phpstan-import-type AssetRow from MediaGalleryGatewayInterface
 */
class MediaGalleryGateway implements MediaGalleryGatewayInterface
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];
    private const SYNC_TOPICS = ['media.gallery.synchronization', 'media.content.synchronization'];
    private const MQ_STATUSES = [2 => 'new', 3 => 'in_progress', 4 => 'complete', 5 => 'retry', 6 => 'error'];
    private const DATE_KEYS = ['trashed_at', 'updated_at', 'created_at'];
    private const SQL_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /**
     * @param SearchAssetsList $searchAssetsList
     * @param MediaMover $mediaMover
     * @param TrashRepository $trashRepository
     * @param GetAssetsByIdsInterface $getAssetsByIds
     * @param GetAssetsByPathsInterface $getAssetsByPaths
     * @param GetAssetsKeywordsInterface $getAssetsKeywords
     * @param IsPathExcludedInterface $isPathExcluded
     * @param CreateDirectoriesByPathsInterface $createDirectories
     * @param ResourceConnection $resourceConnection
     * @param ScopeConfigInterface $scopeConfig
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     * @param TimezoneInterface $timezone
     * @param ResolverInterface $localeResolver
     * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
     */
    public function __construct(
        private readonly SearchAssetsList $searchAssetsList,
        private readonly MediaMover $mediaMover,
        private readonly TrashRepository $trashRepository,
        private readonly GetAssetsByIdsInterface $getAssetsByIds,
        private readonly GetAssetsByPathsInterface $getAssetsByPaths,
        private readonly GetAssetsKeywordsInterface $getAssetsKeywords,
        private readonly IsPathExcludedInterface $isPathExcluded,
        private readonly CreateDirectoriesByPathsInterface $createDirectories,
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly TimezoneInterface $timezone,
        private readonly ResolverInterface $localeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(array $query): array
    {
        $filters = is_array($query['filters'] ?? null) ? $query['filters'] : [];
        $result = $this->searchAssetsList->execute(
            Cast::string($query['directory'] ?? ''),
            Cast::string($query['search'] ?? ''),
            Cast::string($query['content_type'] ?? ''),
            max(1, Cast::int($query['page'] ?? 1)),
            max(1, min(200, Cast::int($query['page_size'] ?? 50))),
            Cast::string($query['sort'] ?? 'updated_at'),
            Cast::string($query['direction'] ?? 'DESC'),
            [],
            Cast::bool($query['all_directories'] ?? false),
            $filters
        );
        $items = [];
        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            if (is_array($item)) {
                unset($item['thumbnail_url']);
                $items[] = $this->normalizeDates($item);
            }
        }
        return ['total' => Cast::int($result['total_count'] ?? 0), 'items' => $items];
    }

    /**
     * @inheritDoc
     */
    public function getByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return array_values(array_map(
            fn (AssetInterface $asset): array => $this->toRow($asset),
            $this->getAssetsByIds->execute($ids)
        ));
    }

    /**
     * @inheritDoc
     */
    public function getByPath(string $path): ?array
    {
        $assets = $this->getAssetsByPaths->execute([$path]);
        $asset = reset($assets);
        return $asset instanceof AssetInterface ? $this->toRow($asset) : null;
    }

    /**
     * @inheritDoc
     */
    public function keywords(int $id): array
    {
        $keywords = [];
        foreach ($this->getAssetsKeywords->execute([$id]) as $assetKeywords) {
            foreach ($assetKeywords->getKeywords() as $keyword) {
                $keywords[] = (string) $keyword->getKeyword();
            }
        }
        return $keywords;
    }

    /**
     * @inheritDoc
     */
    public function magentoUsage(array $ids): array
    {
        $usage = [];
        if ($ids === []) {
            return $usage;
        }
        // One query for all ids; GetContentByAssetIdsInterface drops the asset id from its result.
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('media_content_asset'),
                ['asset_id', 'entity_type', 'entity_id', 'field']
            )
            ->where('asset_id IN (?)', $ids);
        foreach ($connection->fetchAll($select) as $row) {
            $usage[Cast::int($row['asset_id'])][] = [
                'entity_type' => Cast::string($row['entity_type']),
                'entity_id' => Cast::string($row['entity_id']),
                'field' => Cast::string($row['field']),
            ];
        }
        return $usage;
    }

    /**
     * @inheritDoc
     */
    public function move(array $ids, string $targetFolder): array
    {
        $result = $this->mediaMover->moveAssets($ids, $targetFolder);
        // Hyvä returns the skipped file names (name already taken in the target folder).
        $skipped = is_array($result['skipped'] ?? null) ? array_values(array_map('strval', $result['skipped'])) : [];
        return ['moved' => Cast::int($result['moved'] ?? 0), 'skipped' => count($skipped), 'skipped_files' => $skipped];
    }

    /**
     * @inheritDoc
     */
    public function trash(array $ids, ?int $adminUserId): int
    {
        return $this->trashRepository->trash($ids, $adminUserId);
    }

    /**
     * @inheritDoc
     */
    public function trashList(?string $search, int $page, int $pageSize, array $ids = []): array
    {
        $retention = Cast::int($this->scopeConfig->getValue('hyva_cms/media_gallery/trash_retention_days')) ?: 7;
        if ($ids !== []) {
            $connection = $this->resourceConnection->getConnection();
            $rows = $connection->fetchAll($connection->select()
                ->from($this->resourceConnection->getTableName('hyva_media_gallery_trash'))
                ->where('id IN (?)', $ids));
            return [
                'total' => count($rows),
                'retention_days' => $retention,
                'items' => array_values(array_map(fn (array $row): array => $this->normalizeDates($row), $rows)),
            ];
        }
        $list = $this->trashRepository->getList($search ?? '', max(1, $page), max(1, min(200, $pageSize)));
        $items = [];
        foreach (is_array($list['items'] ?? null) ? $list['items'] : [] as $item) {
            if (is_array($item)) {
                $items[] = $this->normalizeDates($item);
            }
        }
        return [
            'total' => Cast::int($list['total_count'] ?? $this->trashRepository->count()),
            'retention_days' => $retention,
            'items' => $items,
        ];
    }

    /**
     * @inheritDoc
     */
    public function restore(array $trashIds): int
    {
        return $this->trashRepository->restore($trashIds);
    }

    /**
     * @inheritDoc
     */
    public function isExcluded(string $path): bool
    {
        return $this->isPathExcluded->execute($path);
    }

    /**
     * @inheritDoc
     */
    public function createFolder(string $path): void
    {
        $this->createDirectories->execute([$path]);
    }

    /**
     * @inheritDoc
     */
    public function fileExists(string $path): bool
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->isFile($path);
    }

    /**
     * @inheritDoc
     */
    public function pathExists(string $path): bool
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->isExist($path);
    }

    /**
     * @inheritDoc
     */
    public function syncSnapshot(): array
    {
        $configured = $this->scopeConfig->getValue(
            'system/media_storage_configuration/allowed_resources/media_gallery_image_folders'
        );
        $folders = array_values(array_filter(array_map(
            static fn ($folder): string => Cast::string($folder),
            is_array($configured) ? array_values($configured) : []
        )));
        $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        /** @var list<string> $files */
        $files = [];
        $pending = $folders;
        while ($pending !== []) {
            $directory = (string) array_pop($pending);
            try {
                $entries = $media->isDirectory($directory) ? $media->read($directory) : [];
            } catch (Throwable $e) {
                // An unreadable folder counts as empty; the sync report goes on.
                $this->logger->warning('Hyvä Media Gallery: could not read a folder.', [
                    'folder' => $directory,
                    'exception' => $e,
                ]);
                $entries = [];
            }
            foreach ($entries as $entry) {
                if (str_contains($entry, '/.')) {
                    continue;
                }
                if ($media->isDirectory($entry)) {
                    $pending[] = $entry;
                    continue;
                }
                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($extension, self::IMAGE_EXTENSIONS, true) && !$this->isPathExcluded->execute($entry)) {
                    $files[] = $entry;
                }
            }
        }
        $connection = $this->resourceConnection->getConnection();
        $assetPaths = array_values(array_map(
            static fn ($path): string => Cast::string($path),
            $connection->fetchCol($connection->select()
                ->from($this->resourceConnection->getTableName('media_gallery_asset'), ['path']))
        ));
        $queue = [];
        $statusRows = $connection->fetchAll($connection->select()
            ->from(['m' => $this->resourceConnection->getTableName('queue_message')], [])
            ->join(['s' => $this->resourceConnection->getTableName('queue_message_status')], 's.message_id = m.id', [
                'status',
                'count' => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->where('m.topic_name IN (?)', self::SYNC_TOPICS)
            ->group('s.status'));
        foreach ($statusRows as $row) {
            $queue[self::MQ_STATUSES[Cast::int($row['status'])] ?? (string) $row['status']] = Cast::int($row['count']);
        }
        return [
            // Magento's "Enable Old Media Gallery" stores 0 for Yes and 1 for No.
            'gallery_enabled' => (bool) $this->scopeConfig->getValue('system/media_gallery/enabled'),
            'folders' => $folders,
            'files' => $files,
            'asset_paths' => $assetPaths,
            'queue' => $queue,
            'trash' => $this->trashRepository->count(),
        ];
    }

    /**
     * Row.
     *
     * @param AssetInterface $asset
     * @return array
     * @phpstan-return AssetRow
     */
    private function toRow(AssetInterface $asset): array
    {
        return [
            'id' => (int) $asset->getId(),
            'path' => $asset->getPath(),
            'title' => (string) $asset->getTitle(),
            'content_type' => $asset->getContentType(),
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'size' => $asset->getSize(),
            'updated_at' => (string) $this->normalizeDate((string) $asset->getUpdatedAt()),
        ];
    }

    /**
     * A media date as UTC "Y-m-d H:i:s", or null when empty.
     *
     * DB values pass as they are; Hyvä's locale strings (store locale and timezone, minute precision) are parsed
     * and converted. Unparsable input is returned unchanged.
     *
     * @param string|null $value
     * @return string|null
     */
    public function normalizeDate(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match(self::SQL_DATE_PATTERN, $value) === 1) {
            return $value;
        }
        try {
            $timezone = $this->timezone->getConfigTimezone() ?: 'UTC';
            $formatter = new IntlDateFormatter(
                $this->localeResolver->getLocale(),
                IntlDateFormatter::MEDIUM,
                IntlDateFormatter::SHORT,
                $timezone
            );
            // Magento swaps ICU's narrow no-break space for a plain one; accept both spellings.
            foreach (array_unique([$value, str_replace(' ', "\u{202F}", $value)]) as $candidate) {
                $timestamp = $formatter->parse($candidate);
                if (is_int($timestamp)) {
                    return (new DateTimeImmutable('@' . $timestamp))
                        ->setTimezone(new DateTimeZone('UTC'))
                        ->format('Y-m-d H:i:s');
                }
            }
        } catch (Throwable $e) {
            // An unparsable date is returned as it is; the reply goes on.
            $this->logger->warning('Hyvä Media Gallery: could not parse a date.', [
                'value' => $value,
                'exception' => $e,
            ]);
        }
        return $value;
    }

    /**
     * Normalise the date keys of one row.
     *
     * @param array $row
     * @return array
     * @phpstan-param array<mixed> $row
     * @phpstan-return array<mixed>
     */
    private function normalizeDates(array $row): array
    {
        foreach (self::DATE_KEYS as $key) {
            if (array_key_exists($key, $row) && (is_string($row[$key]) || $row[$key] === null)) {
                $row[$key] = $this->normalizeDate($row[$key]);
            }
        }
        return $row;
    }
}
