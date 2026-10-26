<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Image;

use Magebit\McpHyvaCommerceTools\Api\ImageFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaInventory;

/**
 * Media Gallery asset (title, description), or null when the file is not in the gallery.
 */
class GalleryResolver implements ImageFieldResolverInterface
{
    public const KEY = 'gallery';

    /**
     * @param MediaInventory $inventory
     */
    public function __construct(
        private readonly MediaInventory $inventory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 20;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, array $args): mixed
    {
        unset($args);
        $asset = $this->inventory->getGalleryAsset($path);
        if ($asset === null) {
            return null;
        }
        unset($asset['backup_image_path']);
        return $asset;
    }
}
