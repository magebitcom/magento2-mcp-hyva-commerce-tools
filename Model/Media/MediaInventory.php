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
     * @param ResourceConnection $resourceConnection
     * @param MediaFileInfo $mediaFileInfo
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MediaFileInfo $mediaFileInfo
    ) {
    }

    /**
     * Gallery assets keyed by path.
     *
     * @return array
     * @phpstan-return array<string, array{asset_id: int, title: string, description: string|null, width: int,
     *     height: int, size: int, content_type: string, backup_image_path: string|null}>
     */
    public function getGalleryAssets(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('media_gallery_asset');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $hasBackup = $connection->tableColumnExists($table, 'backup_image_path');
        $columns = ['id', 'path', 'title', 'description', 'width', 'height', 'size', 'content_type'];
        if ($hasBackup) {
            $columns[] = 'backup_image_path';
        }
        $assets = [];
        foreach ($connection->fetchAll($connection->select()->from($table, $columns)) as $row) {
            $assets[Cast::string($row['path'])] = [
                'asset_id' => Cast::int($row['id']),
                'title' => Cast::string($row['title']),
                'description' => Cast::nullableString($row['description']),
                'width' => Cast::int($row['width']),
                'height' => Cast::int($row['height']),
                'size' => Cast::int($row['size']),
                'content_type' => Cast::string($row['content_type']),
                'backup_image_path' => $hasBackup ? Cast::nullableString($row['backup_image_path']) : null,
            ];
        }
        return $assets;
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
