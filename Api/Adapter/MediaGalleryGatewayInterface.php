<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

use Magento\Framework\Exception\LocalizedException;

/**
 * Hyvä Media Gallery 1.0 access (assets in media_gallery_asset, moves, Trash) plus Magento's MediaGallery APIs.
 * Only the adapter references Hyva\MediaGallery classes; tools reach it through a proxy after the feature check.
 *
 * @phpstan-type AssetRow array{id: int, path: string, title: string, content_type: string, width: int,
 *     height: int, size: int, updated_at: string}
 */
interface MediaGalleryGatewayInterface
{
    /**
     * Search assets (Hyvä's SearchAssetsList).
     *
     * @param array $query directory, search, content_type, page, page_size, sort, all_directories, filters
     * @return array
     * @phpstan-param array<string, mixed> $query
     * @phpstan-return array{total: int, items: list<array<string, mixed>>}
     */
    public function search(array $query): array;

    /**
     * Assets by id.
     *
     * @param array $ids
     * @return array
     * @phpstan-param list<int> $ids
     * @phpstan-return list<AssetRow>
     */
    public function getByIds(array $ids): array;

    /**
     * Asset by media path, or null.
     *
     * @param string $path
     * @return array|null
     * @phpstan-return AssetRow|null
     */
    public function getByPath(string $path): ?array;

    /**
     * Keywords of one asset.
     *
     * @param int $id
     * @return array
     * @phpstan-return list<string>
     */
    public function keywords(int $id): array;

    /**
     * Magento-tracked usage (media_content_asset: CMS page/block content, catalog) per asset id.
     *
     * @param array $ids
     * @return array
     * @phpstan-param list<int> $ids
     * @phpstan-return array<int, list<array{entity_type: string, entity_id: string, field: string}>>
     */
    public function magentoUsage(array $ids): array;

    /**
     * Move assets to a folder; ids are kept. Returns moved and skipped (name taken) counts and skipped names.
     *
     * @param array $ids
     * @param string $targetFolder
     * @return array
     * @throws LocalizedException
     * @phpstan-param list<int> $ids
     * @phpstan-return array{moved: int, skipped: int, skipped_files: list<string>}
     */
    public function move(array $ids, string $targetFolder): array;

    /**
     * Move assets to Hyvä's Trash (restorable for the retention period).
     *
     * @param array $ids
     * @param int|null $adminUserId
     * @return int
     * @throws LocalizedException
     * @phpstan-param list<int> $ids
     */
    public function trash(array $ids, ?int $adminUserId): int;

    /**
     * Trash items.
     *
     * @param string|null $search
     * @param int $page
     * @param int $pageSize
     * @param array $ids Only these trash ids.
     * @return array
     * @phpstan-param list<int> $ids
     * @phpstan-return array{total: int, retention_days: int, items: list<array<string, mixed>>}
     */
    public function trashList(?string $search, int $page, int $pageSize, array $ids = []): array;

    /**
     * Restore trash items to their original paths.
     *
     * @param array $trashIds
     * @return int
     * @throws LocalizedException
     * @phpstan-param list<int> $trashIds
     */
    public function restore(array $trashIds): int;

    /**
     * Whether Magento excludes the path from the media gallery.
     *
     * @param string $path
     * @return bool
     */
    public function isExcluded(string $path): bool;

    /**
     * Create a folder.
     *
     * @param string $path
     * @return void
     * @throws LocalizedException
     */
    public function createFolder(string $path): void;

    /**
     * Whether a file exists under pub/media.
     *
     * @param string $path
     * @return bool
     */
    public function fileExists(string $path): bool;

    /**
     * Whether anything (file or folder) exists under pub/media: the rule Hyvä's trash restore uses before it moves a
     * file back (TrashRepository::restore() skips the move but still drops the trash row).
     *
     * @param string $path
     * @return bool
     */
    public function pathExists(string $path): bool;

    /**
     * Files in the media gallery folders on disk, the asset paths in the database, and sync queue state.
     *
     * @return array
     * @phpstan-return array{gallery_enabled: bool, folders: list<string>, files: list<string>,
     *     asset_paths: list<string>, queue: array<string, int>, trash: int}
     */
    public function syncSnapshot(): array;
}
