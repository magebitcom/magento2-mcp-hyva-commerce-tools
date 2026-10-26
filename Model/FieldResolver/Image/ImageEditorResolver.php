<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Image;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Api\ImageFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaInventory;

/**
 * Image Editor state: whether an original backup exists so the image can be restored.
 */
class ImageEditorResolver implements ImageFieldResolverInterface
{
    public const KEY = 'image_editor';

    /**
     * @param MediaInventory $inventory
     * @param MediaFileInfo $fileInfo
     * @param FeatureCheckerInterface $featureChecker
     */
    public function __construct(
        private readonly MediaInventory $inventory,
        private readonly MediaFileInfo $fileInfo,
        private readonly FeatureCheckerInterface $featureChecker
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
        return 50;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, array $args): mixed
    {
        unset($args);
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::IMAGE_EDITOR)) {
            return ['available' => false];
        }
        $backup = $this->inventory->getGalleryAssets()[$path]['backup_image_path'] ?? null;
        return [
            'available' => true,
            'editable_format' => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp'],
                true
            ),
            'has_backup' => $backup !== null && $backup !== '',
            'backup_path' => $backup,
            'backup_exists' => $backup !== null && $backup !== '' && $this->fileInfo->exists($backup),
        ];
    }
}
