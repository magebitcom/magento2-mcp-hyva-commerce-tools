<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\MediaOptimization;

use Magento\Framework\Exception\LocalizedException;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Magebit\Mcp\Api\LoggerInterface;
use Hyva\MediaOptimization\Model\Config\GeneralConfig;
use Hyva\MediaOptimization\Model\Config\HtmlCssReplacementConfig;
use Hyva\MediaOptimization\Model\Config\PostHocConfig;
use Hyva\MediaOptimization\Service\Cleaner;
use Hyva\MediaOptimization\Service\ImagePathResolver;
use Hyva\MediaOptimization\Service\Resize\EnginePool as ResizeEnginePool;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaOptimizationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

class MediaOptimizationGateway implements MediaOptimizationGatewayInterface
{
    private const CONVERTED_FORMATS = ['webp', 'avif', 'jpg', 'jpeg', 'png', 'gif'];

    /**
     * @param OptionalServiceFactory $optionalServices
     * @param StoreManagerInterface $storeManager
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getVariants(string $path): array
    {
        $media = $this->media();
        $resizedDir = $this->imagePathResolver()->resolveResizedDirectory($path);
        $resized = [];
        foreach ($this->filesUnder($media, $resizedDir) as $file) {
            $resized[] = $file;
        }

        $converted = [];
        $candidates = $this->filesUnder($media, ImagePathResolver::CONVERTED_DIR . '/' . $resizedDir);
        $info = pathinfo($path);
        $base = (isset($info['dirname']) && $info['dirname'] !== '.' ? $info['dirname'] . '/' : '') . $info['filename'];
        foreach (['webp', 'avif'] as $format) {
            $candidate = ImagePathResolver::CONVERTED_DIR . '/' . $base . '.' . $format;
            if ($media->isFile($candidate)) {
                $candidates[] = $this->fileRow($media, $candidate);
            }
        }
        foreach ($candidates as $file) {
            $converted[] = $file + ['format' => strtolower(pathinfo($file['path'], PATHINFO_EXTENSION))];
        }

        $times = array_merge(array_column($resized, 'modified_at'), array_column($converted, 'modified_at'));
        rsort($times);
        return [
            'resized' => $resized,
            'converted' => $converted,
            'formats' => array_values(array_unique(array_column($converted, 'format'))),
            'latest_generated_at' => $times[0] ?? null,
        ];
    }

    /**
     * @inheritDoc
     */
    public function clear(string $path, string $mode = 'all'): int
    {
        $before = $this->countVariants($path);
        if ($mode !== 'converted') {
            // Deletes resized/<md5(path)> only.
            $this->cleaner()->clearResizedMediaByPath($path);
        }
        if ($mode !== 'resized') {
            // Not delegated to Cleaner::clearConvertedMediaByPath(): for a file in the media root it searches
            // "<filename>.*" in the media root itself and deletes the original image.
            $this->deleteConvertedVariants($path);
        }
        return max(0, $before - $this->countVariants($path));
    }

    /**
     * Delete exactly the converted variants of one path.
     *
     * Removes converted/resized/<md5(path)>/ and converted/<dir>/<file>.<fmt>.
     *
     * @param string $path
     * @return void
     */
    private function deleteConvertedVariants(string $path): void
    {
        $writer = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $convertedOfResized = ImagePathResolver::CONVERTED_DIR
            . '/'
            . $this->imagePathResolver()->resolveResizedDirectory($path);
        if ($writer->isDirectory($convertedOfResized)) {
            $writer->delete($convertedOfResized);
        }
        foreach (self::CONVERTED_FORMATS as $format) {
            $converted = $this->imagePathResolver()->resolveConvertedPath($path, $format);
            if (str_starts_with($converted, ImagePathResolver::CONVERTED_DIR . '/') && $writer->isFile($converted)) {
                $writer->delete($converted);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function clearAll(string $mode = 'all'): int
    {
        $before = $this->countAllGenerated();
        match ($mode) {
            'resized' => $this->cleaner()->clearAllResizedMedia(),
            'converted' => $this->cleaner()->clearAllConvertedMedia(),
            default => $this->cleaner()->clearAllOptimizedMedia(),
        };
        $after = $this->countAllGenerated();
        return max(0, ($before['resized'] + $before['converted']) - ($after['resized'] + $after['converted']));
    }

    /**
     * @inheritDoc
     */
    public function countAllGenerated(): array
    {
        $media = $this->media();
        return [
            'resized' => count($this->filesUnder($media, ImagePathResolver::RESIZED_DIR)),
            'converted' => count($this->filesUnder($media, ImagePathResolver::CONVERTED_DIR)),
        ];
    }

    /**
     * @inheritDoc
     */
    public function previewPicture(array $images, array $imgAttributes = []): array
    {
        $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $engine = $this->resizeEnginePool()->getEngineByCode((string) $this->generalConfig()->getResizeEngine());
        $sources = [];
        $missing = [];
        $fallback = null;
        foreach ($images as $image) {
            $path = Cast::string($image['path'] ?? '');
            if ($path === '' || !$media->isExist($path)) {
                continue;
            }
            $width = is_numeric($image['width'] ?? null) ? (int) $image['width'] : null;
            $height = is_numeric($image['height'] ?? null) ? (int) $image['height'] : null;
            $currentSize = null;
            if ($engine !== null) {
                $size = $engine->getCurrentSize($path);
                $currentSize = ['width' => Cast::int($size['width'] ?? 0), 'height' => Cast::int($size['height'] ?? 0)];
            }
            $originalFormat = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            // Density => path of the original-format version (the original itself without display dimensions).
            $base = [];
            $desired = $this->desiredSizes($currentSize, $width, $height);
            foreach ($desired as $density => $dimensions) {
                if ($dimensions === null) {
                    $base[$density] = $path;
                    continue;
                }
                $resized = $this->imagePathResolver()->resolveResizedPath(
                    $path,
                    sprintf('%sx%s', $dimensions['width'], $dimensions['height']),
                    $density
                );
                $base[$density] = $resized;
                if (!$media->isExist($resized)) {
                    $missing[] = ['path' => $resized, 'source' => $path, 'kind' => 'resized', 'density' => $density,
                        'format' => $originalFormat];
                }
            }
            $byFormat = [$originalFormat => $base];
            foreach ($this->generalConfig()->getOutputFormats() as $format) {
                $format = strtolower(trim((string) $format));
                if ($format === '' || $format === $originalFormat) {
                    continue;
                }
                foreach ($base as $density => $basePath) {
                    $converted = $this->imagePathResolver()->resolveConvertedPath($basePath, $format);
                    $byFormat[$format][$density] = $converted;
                    if (!$media->isExist($converted)) {
                        $missing[] = ['path' => $converted, 'source' => $path, 'kind' => 'converted',
                            'density' => $density, 'format' => $format];
                    }
                }
            }
            // Hyvä lists the extra formats before the original format; only existing files go into srcset.
            foreach (array_reverse($byFormat, true) as $format => $versions) {
                $parts = [];
                foreach ($versions as $density => $versionPath) {
                    if ($media->isExist($versionPath)) {
                        $parts[] = $this->mediaUrl($versionPath) . ' ' . $density;
                    }
                }
                if ($parts !== []) {
                    $sources[] = [
                        'type' => $this->mimeType((string) $format),
                        'media' => is_string($image['media'] ?? null) ? $image['media'] : null,
                        'srcset' => implode(', ', $parts),
                    ];
                }
            }
            if ($fallback === null) {
                $display = $desired['1x'] ?? null;
                $src = isset($base['1x']) && $media->isExist($base['1x']) ? $base['1x'] : $path;
                $fallback = ['src' => $this->mediaUrl($src)]
                    + array_filter([
                        'width' => $display['width'] ?? $width ?? $currentSize['width'] ?? null,
                        'height' => $display['height'] ?? $height ?? $currentSize['height'] ?? null,
                    ], static fn ($v): bool => $v !== null && $v !== 0);
            }
        }
        return ['html' => $this->pictureHtml($sources, $fallback, $imgAttributes), 'sources' => $sources,
            'missing' => $missing];
    }

    /**
     * Densities Hyvä's Optimizer would serve (null dimensions: the original file as 1x).
     *
     * @param array|null $currentSize
     * @param int|null $width
     * @param int|null $height
     * @return array
     * @phpstan-param array{width: int, height: int}|null $currentSize
     * @phpstan-return array<string, array{width: int, height: int}|null>
     */
    private function desiredSizes(?array $currentSize, ?int $width, ?int $height): array
    {
        if ($width === null && $height === null) {
            return ['1x' => null];
        }
        if ($currentSize === null || $currentSize['width'] <= 0 || $currentSize['height'] <= 0) {
            return [];
        }
        if ($width === null) {
            $width = (int) round((int) $height * $currentSize['width'] / $currentSize['height']);
        } elseif ($height === null) {
            $height = (int) round($width * $currentSize['height'] / $currentSize['width']);
        }
        $sizes = ['1x' => ['width' => $width, 'height' => $height]];
        if ($this->generalConfig()->isRetinaEnabled()) {
            foreach ([2, 3] as $factor) {
                if ($currentSize['width'] >= $width * $factor && $currentSize['height'] >= $height * $factor) {
                    $sizes[$factor . 'x'] = ['width' => $width * $factor, 'height' => $height * $factor];
                }
            }
        }
        return $sizes;
    }

    /**
     * Picture markup from the existing sources.
     *
     * @param array $sources
     * @param array|null $fallback
     * @param array $imgAttributes
     * @return string
     * @phpstan-param list<array{type: string|null, media: string|null, srcset: string}> $sources
     * @phpstan-param array<string, string|int>|null $fallback
     * @phpstan-param array<string, string> $imgAttributes
     */
    private function pictureHtml(array $sources, ?array $fallback, array $imgAttributes): string
    {
        if ($fallback === null) {
            return '';
        }
        $attributes = static function (array $values): string {
            $html = '';
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"';
                }
            }
            return $html;
        };
        $html = '<picture>';
        foreach ($sources as $source) {
            $html .= '<source' . $attributes($source) . '>';
        }
        return $html . '<img' . $attributes($fallback + $imgAttributes) . '></picture>';
    }

    /**
     * Media URL of a path in pub/media.
     *
     * @param string $path
     * @return string
     */
    private function mediaUrl(string $path): string
    {
        $store = $this->storeManager->getStore();
        $base = $store instanceof Store ? $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) : '/media/';
        return rtrim(Cast::string($base), '/') . '/' . ltrim($path, '/');
    }

    /**
     * MIME type of an image format.
     *
     * @param string $format
     * @return string
     */
    private function mimeType(string $format): string
    {
        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            default => 'image/' . $format,
        };
    }

    /**
     * Count variants.
     *
     * @param string $path
     * @return int
     */
    private function countVariants(string $path): int
    {
        $variants = $this->getVariants($path);
        return count($variants['resized']) + count($variants['converted']);
    }

    /**
     * Files under.
     *
     * @param ReadInterface $media
     * @param string $directory
     * @return array
     * @phpstan-return list<array{path: string, size: int, modified_at: string}>
     */
    private function filesUnder(ReadInterface $media, string $directory): array
    {
        $files = [];
        $pending = [$directory];
        while ($pending !== []) {
            $current = (string) array_pop($pending);
            try {
                if (!$media->isDirectory($current)) {
                    continue;
                }
                foreach ($media->read($current) as $entry) {
                    if ($media->isDirectory($entry)) {
                        $pending[] = $entry;
                    } else {
                        $files[] = $this->fileRow($media, $entry);
                    }
                }
            } catch (Throwable $e) {
                // An unreadable folder is skipped; the listing goes on.
                $this->logger->warning('Hyvä media optimization: could not read a folder.', [
                    'exception' => $e,
                ]);
                continue;
            }
        }
        return $files;
    }

    /**
     * File row.
     *
     * @param ReadInterface $media
     * @param string $path
     * @return array
     * @phpstan-return array{path: string, size: int, modified_at: string}
     */
    private function fileRow(ReadInterface $media, string $path): array
    {
        $stat = $media->stat($path);
        return [
            'path' => $path,
            'size' => Cast::int($stat['size'] ?? 0),
            'modified_at' => gmdate('Y-m-d H:i:s', Cast::int($stat['mtime'] ?? 0)),
        ];
    }

    /**
     * Media.
     *
     * @return ReadInterface
     */
    private function media(): ReadInterface
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
    }

    /**
     * @inheritDoc
     */
    public function getConfigSummary(?int $storeId = null): array
    {
        return [
            'enabled' => $this->generalConfig()->isOptimizationEnabled($storeId),
            'retina' => $this->generalConfig()->isRetinaEnabled($storeId),
            'resize_engine' => $this->generalConfig()->getResizeEngine($storeId),
            'conversion_engine' => $this->generalConfig()->getConversionEngine($storeId),
            'output_formats' => array_values($this->generalConfig()->getOutputFormats($storeId)),
            'default_output_format' => $this->generalConfig()->getDefaultOutputFormat($storeId),
            'automatic_replacement' => $this->replacementConfig()->isAutomaticReplacementEnabled($storeId),
            'html_replace_mode' => $this->replacementConfig()->getHtmlReplaceMode($storeId),
            'css_replace_mode' => $this->replacementConfig()->getCssReplaceMode($storeId),
            'convertible_formats' => array_values($this->replacementConfig()->getConvertibleFormats($storeId)),
            'post_hoc_resize' => $this->postHocConfig()->isResizePostHocEnabled($storeId),
            'post_hoc_convert' => $this->postHocConfig()->isConvertPostHocEnabled($storeId),
            'debug_log' => $this->generalConfig()->isDebugLogEnabled($storeId),
            'image_request_log' => $this->generalConfig()->isImageRequestLogEnabled($storeId),
            'media_url_to_path_resolution' => $this->generalConfig()->isMediaUrlToPathResolutionEnabled($storeId),
        ];
    }

    /**
     * GeneralConfig from the optional package, created on first use.
     *
     * @return GeneralConfig
     * @throws LocalizedException When the package is not installed.
     */
    private function generalConfig(): GeneralConfig
    {
        return $this->optionalServices->get(GeneralConfig::class);
    }

    /**
     * HtmlCssReplacementConfig from the optional package, created on first use.
     *
     * @return HtmlCssReplacementConfig
     * @throws LocalizedException When the package is not installed.
     */
    private function replacementConfig(): HtmlCssReplacementConfig
    {
        return $this->optionalServices->get(HtmlCssReplacementConfig::class);
    }

    /**
     * PostHocConfig from the optional package, created on first use.
     *
     * @return PostHocConfig
     * @throws LocalizedException When the package is not installed.
     */
    private function postHocConfig(): PostHocConfig
    {
        return $this->optionalServices->get(PostHocConfig::class);
    }

    /**
     * ImagePathResolver from the optional package, created on first use.
     *
     * @return ImagePathResolver
     * @throws LocalizedException When the package is not installed.
     */
    private function imagePathResolver(): ImagePathResolver
    {
        return $this->optionalServices->get(ImagePathResolver::class);
    }

    /**
     * Cleaner from the optional package, created on first use.
     *
     * @return Cleaner
     * @throws LocalizedException When the package is not installed.
     */
    private function cleaner(): Cleaner
    {
        return $this->optionalServices->get(Cleaner::class);
    }

    /**
     * ResizeEnginePool from the optional package, created on first use.
     *
     * @return ResizeEnginePool
     * @throws LocalizedException When the package is not installed.
     */
    private function resizeEnginePool(): ResizeEnginePool
    {
        return $this->optionalServices->get(ResizeEnginePool::class);
    }
}
