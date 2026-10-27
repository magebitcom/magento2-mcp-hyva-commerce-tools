<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

/**
 * Module-owned boundary to Hyva_MediaOptimization. Only the implementation may reference Hyva\MediaOptimization
 * classes.
 */
interface MediaOptimizationGatewayInterface
{
    /**
     * Effective optimization settings, read through Hyvä's own config classes.
     *
     * @param int|null $storeId
     * @return array
     * @phpstan-return array<string, mixed>
     */
    public function getConfigSummary(?int $storeId = null): array;

    /**
     * Generated variants of one media path that exist on disk (nothing is generated).
     *
     * @param string $path Relative to pub/media.
     * @return array
     * @phpstan-return array{resized: list<array{path: string, size: int, modified_at: string}>,
     *     converted: list<array{path: string, format: string, size: int, modified_at: string}>,
     *     formats: list<string>, latest_generated_at: string|null}
     */
    public function getVariants(string $path): array;

    /**
     * Clear.
     *
     * @param string $path
     * @param string $mode all|resized|converted
     * @return int Number of generated files removed.
     */
    public function clear(string $path, string $mode = 'all'): int;

    /**
     * Remove every generated image.
     *
     * @param string $mode all|resized|converted
     * @return int Number of generated files removed.
     */
    public function clearAll(string $mode = 'all'): int;

    /**
     * Count all generated.
     *
     * @return array
     * @phpstan-return array{resized: int, converted: int}
     */
    public function countAllGenerated(): array;

    /**
     * Responsive <picture> markup built only from variants that already exist (never generates files)
     *
     * Follows Hyvä's Optimizer plan (sizes 1x/2x/3x with retina, configured output formats, Hyvä's variant paths)
     * but reads only: a variant that does not exist yet is listed in `missing` instead of being created. Hyvä creates
     * it when the image is next rendered on the storefront.
     *
     * @param array $images Entries {path, width?, height?, media?, sizes?}
     * @param array $imgAttributes
     * @return array
     * @phpstan-param list<array<string, mixed>> $images
     * @phpstan-param array<string, string> $imgAttributes
     * @phpstan-return array{html: string, sources: list<array{type: string|null, media: string|null,
     *     srcset: string}>, missing: list<array{path: string, source: string, kind: string, density: string,
     *     format: string}>}
     */
    public function previewPicture(array $images, array $imgAttributes = []): array;
}
