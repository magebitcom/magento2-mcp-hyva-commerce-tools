<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;

/**
 * Lists media images from the Media Gallery (media_gallery_asset) and the filesystem.
 *
 * @phpstan-type GalleryAsset array{asset_id: int, title: string, description: string|null, width: int, height: int,
 *     size: int, content_type: string, backup_image_path: string|null}
 */
class MediaInventory
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'tif', 'tiff'];
    private const SKIP_DIRECTORIES = [
        'resized',
        'converted',
        'catalog/product/cache',
        'tmp',
        'hyva_image_editor_backup',
        '.thumbs',
        'captcha',
        'analytics',
    ];

    /**
     * @var array|null
     * @phpstan-var array<string, GalleryAsset>|null
     */
    private ?array $assets = null;

    /**
     * @var array
     * @phpstan-var array<string, GalleryAsset|null>
     */
    private array $assetsByPath = [];

    /**
     * @var array|false|null
     * @phpstan-var array{table: string, columns: list<string>, has_backup: bool}|false|null
     */
    private array|false|null $tableInfo = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param MediaFileInfo $mediaFileInfo
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MediaFileInfo $mediaFileInfo
    ) {
    }

    /**
     * Gallery assets keyed by path, read once per request.
     *
     * @return array
     * @phpstan-return array<string, GalleryAsset>
     */
    public function getGalleryAssets(): array
    {
        if ($this->assets !== null) {
            return $this->assets;
        }
        $this->assets = $this->fetch(null);
        return $this->assets;
    }

    /**
     * One gallery asset by path (WHERE path = ?), memoised per path.
     *
     * @param string $path
     * @return array|null
     * @phpstan-return GalleryAsset|null
     */
    public function getGalleryAsset(string $path): ?array
    {
        if ($this->assets !== null) {
            return $this->assets[$path] ?? null;
        }
        if (!array_key_exists($path, $this->assetsByPath)) {
            $this->assetsByPath[$path] = $this->fetch($path)[$path] ?? null;
        }
        return $this->assetsByPath[$path];
    }

    /**
     * Read media_gallery_asset rows, all or those with one path.
     *
     * @param string|null $path
     * @return array
     * @phpstan-return array<string, GalleryAsset>
     */
    private function fetch(?string $path): array
    {
        $info = $this->tableInfo();
        if ($info === false) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($info['table'], $info['columns']);
        if ($path !== null) {
            $select->where('path = ?', $path);
        }
        $assets = [];
        foreach ($connection->fetchAll($select) as $row) {
            $assets[Cast::string($row['path'])] = [
                'asset_id' => Cast::int($row['id']),
                'title' => Cast::string($row['title']),
                'description' => Cast::nullableString($row['description']),
                'width' => Cast::int($row['width']),
                'height' => Cast::int($row['height']),
                'size' => Cast::int($row['size']),
                'content_type' => Cast::string($row['content_type']),
                'backup_image_path' => $info['has_backup'] ? Cast::nullableString($row['backup_image_path']) : null,
            ];
        }
        return $assets;
    }

    /**
     * The media_gallery_asset table and the columns to read, or false when the table is missing.
     *
     * @return array|false
     * @phpstan-return array{table: string, columns: list<string>, has_backup: bool}|false
     */
    private function tableInfo(): array|false
    {
        if ($this->tableInfo !== null) {
            return $this->tableInfo;
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('media_gallery_asset');
        if (!$connection->isTableExists($table)) {
            return $this->tableInfo = false;
        }
        $hasBackup = $connection->tableColumnExists($table, 'backup_image_path');
        $columns = ['id', 'path', 'title', 'description', 'width', 'height', 'size', 'content_type'];
        if ($hasBackup) {
            $columns[] = 'backup_image_path';
        }
        return $this->tableInfo = ['table' => $table, 'columns' => $columns, 'has_backup' => $hasBackup];
    }

    /**
     * Image paths under a media folder (recursive), skipping generated/cache directories.
     *
     * @param string $folder Relative to pub/media, e.g. "wysiwyg".
     * @param int $limit
     * @return array
     * @phpstan-return list<string>
     */
    public function scanFolder(string $folder, int $limit = 5000): array
    {
        $paths = [];
        foreach ($this->mediaFileInfo->listFiles(trim($folder, '/'), $limit * 4) as $entry) {
            if (count($paths) >= $limit) {
                break;
            }
            foreach (self::SKIP_DIRECTORIES as $dir) {
                if (str_starts_with($entry, $dir . '/') || str_contains($entry, '/' . $dir . '/')) {
                    continue 2;
                }
            }
            if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
                $paths[] = $entry;
            }
        }
        sort($paths);
        return $paths;
    }
}
