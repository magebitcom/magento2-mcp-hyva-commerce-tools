<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

/**
 * Compares the media gallery folders on disk with media_gallery_asset (no Hyvä or Magento status service exists).
 */
class MediaSyncReport
{
    /**
     * Compare.
     *
     * @param array $files Image paths found on disk.
     * @param array $assetPaths Paths in media_gallery_asset.
     * @param int $sample How many example paths to list per difference.
     * @return array
     * @phpstan-param list<string> $files
     * @phpstan-param list<string> $assetPaths
     * @phpstan-return array{in_sync: bool, files_on_disk: int, assets_in_db: int, missing_in_db: int,
     *     missing_on_disk: int, missing_in_db_sample: list<string>, missing_on_disk_sample: list<string>}
     */
    public function compare(array $files, array $assetPaths, int $sample = 10): array
    {
        $onDisk = array_fill_keys($files, true);
        $inDb = array_fill_keys($assetPaths, true);
        $missingInDb = array_map('strval', array_keys(array_diff_key($onDisk, $inDb)));
        $missingOnDisk = array_map('strval', array_keys(array_diff_key($inDb, $onDisk)));
        sort($missingInDb);
        sort($missingOnDisk);
        return [
            'in_sync' => $missingInDb === [] && $missingOnDisk === [],
            'files_on_disk' => count($onDisk),
            'assets_in_db' => count($inDb),
            'missing_in_db' => count($missingInDb),
            'missing_on_disk' => count($missingOnDisk),
            'missing_in_db_sample' => array_slice($missingInDb, 0, $sample),
            'missing_on_disk_sample' => array_slice($missingOnDisk, 0, $sample),
        ];
    }
}
