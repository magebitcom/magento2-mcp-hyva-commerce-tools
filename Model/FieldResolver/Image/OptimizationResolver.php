<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Image;

use Magebit\McpHyvaCommerceTools\Api\ImageFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;

/**
 * Generated variants, WebP/AVIF state and responsive output, or availability info without Media Optimization.
 */
class OptimizationResolver implements ImageFieldResolverInterface
{
    public const KEY = 'optimization';

    /**
     * @param MediaService $mediaService
     */
    public function __construct(
        private readonly MediaService $mediaService
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
        return 40;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, array $args): mixed
    {
        unset($args);
        $variants = $this->mediaService->getVariants($path);
        if ($variants === null) {
            return ['available' => false];
        }
        $formats = $this->mediaService->getOutputFormats();
        return [
            'available' => true,
            'configured_formats' => $formats,
            'webp' => in_array('webp', $variants['formats'], true),
            'avif' => in_array('avif', $variants['formats'], true),
            'responsive_output' => $formats !== [],
            'variants' => $variants,
        ];
    }
}
