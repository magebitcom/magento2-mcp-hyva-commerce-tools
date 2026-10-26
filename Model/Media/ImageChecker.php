<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\Mcp\Api\LoggerInterface;
use Throwable;

/**
 * Per-image checks shared by hyva_media.image.validate, the media audits and the media widgets.
 *
 * @phpstan-import-type ImageUsage from ImageUsageIndex
 */
class ImageChecker
{
    public const DEFAULT_MAX_KB = 500;
    public const DEFAULT_MAX_DIMENSION = 2560;
    public const LARGE_PNG_KB = 300;
    public const HERO_KB = 300;
    private const HERO_COMPONENTS = ['banner', 'slider'];
    private const UNSUPPORTED = ['bmp', 'tif', 'tiff', 'heic'];
    private const EDITOR_FORMATS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @param MediaFileInfo $mediaFileInfo
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly MediaFileInfo $mediaFileInfo,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check.
     *
     * @param string $path
     * @param array $usages
     * @param array $options deep = also read file bytes (transparency,
     *     animation); variants = Media Optimization variants (null = feature off).
     * @return array
     * @phpstan-param list<ImageUsage> $usages
     * @phpstan-param array{max_kb?: int, max_dimension?: int, deep?: bool, variants?: array{resized: list<mixed>,
     *     converted: list<mixed>, formats: list<string>}|null, output_formats?: list<string>} $options
     * @phpstan-return array{file: array{path: string, exists: bool, size: int|null, width: int|null,
     *     height: int|null, mime: string|null, modified_at: string|null, extension: string},
     *     issues: list<array{severity: string, code: string, message: string}>}
     */
    public function check(string $path, array $usages, array $options = []): array
    {
        $file = $this->mediaFileInfo->describe($path);
        $issues = [];
        $maxKb = $options['max_kb'] ?? self::DEFAULT_MAX_KB;
        $maxDim = $options['max_dimension'] ?? self::DEFAULT_MAX_DIMENSION;
        $ext = $file['extension'];

        if (!$file['exists']) {
            $issues[] = $this->issue(
                'error',
                'broken_reference',
                sprintf('%s is referenced %d time(s) but the file does not exist.', $path, count($usages))
            );
            return ['file' => $file, 'issues' => $issues];
        }
        $sizeKb = (int) round(($file['size'] ?? 0) / 1024);
        if (in_array($ext, self::UNSUPPORTED, true)) {
            $issues[] = $this->issue(
                'warning',
                'unsupported_format',
                sprintf('.%s is not a web image format; convert to JPG/PNG/WebP.', $ext)
            );
        }
        if ($sizeKb > $maxKb) {
            $issues[] = $this->issue(
                'warning',
                'oversized_file',
                sprintf('%d KB is over the %d KB limit.', $sizeKb, $maxKb)
            );
        }
        if (($file['width'] ?? 0) > $maxDim || ($file['height'] ?? 0) > $maxDim) {
            $issues[] = $this->issue(
                'warning',
                'oversized_dimensions',
                sprintf('%dx%d px exceeds %d px.', (int) $file['width'], (int) $file['height'], $maxDim)
            );
        }
        if ($ext === 'png' && $sizeKb > self::LARGE_PNG_KB) {
            $issues[] = $this->issue(
                'warning',
                'large_png',
                sprintf('PNG of %d KB; a JPG or WebP would usually be much smaller.', $sizeKb)
            );
        }

        $missingAlt = array_filter($usages, static fn (array $u): bool => !$u['has_alt']);
        if ($missingAlt !== []) {
            $issues[] = $this->issue(
                'warning',
                'missing_alt',
                sprintf('%d of %d usage(s) have no alt text.', count($missingAlt), count($usages))
            );
        }
        foreach ($usages as $usage) {
            if (in_array($usage['component'], self::HERO_COMPONENTS, true) && $sizeKb > self::HERO_KB) {
                $issues[] = $this->issue(
                    'warning',
                    'heavy_hero',
                    sprintf(
                        'Used in a %s component at %d KB; hero images should stay under %d KB.',
                        (string) $usage['component'],
                        $sizeKb,
                        self::HERO_KB
                    )
                );
                break;
            }
        }

        $variants = $options['variants'] ?? null;
        if ($variants !== null && $ext !== 'svg' && $variants['resized'] === [] && $variants['converted'] === []) {
            $issues[] = $this->issue(
                'info',
                'not_optimized',
                'No optimized variants exist yet (Hyvä generates them on the first storefront request).'
            );
        } elseif ($variants !== null && $ext !== 'svg') {
            foreach ($options['output_formats'] ?? [] as $format) {
                // Hyvä's ImageConverter skips a file already in the target format, so no variant is expected.
                if ($ext !== $format && !in_array($format, $variants['formats'], true)) {
                    $issues[] = $this->issue(
                        'info',
                        'missing_' . $format,
                        sprintf('No %s variant exists yet.', strtoupper($format))
                    );
                }
            }
        }

        if (!empty($options['deep'])) {
            if (!in_array($ext, self::EDITOR_FORMATS, true)) {
                $issues[] = $this->issue(
                    'info',
                    'editor_unsupported',
                    sprintf('The Hyvä Image Editor cannot edit .%s files.', $ext)
                );
            }
            $bytes = $this->head($path, 65536);
            if ($ext === 'png' && $this->pngHasAlpha($bytes) && $sizeKb > 100) {
                $issues[] = $this->issue(
                    'info',
                    'transparent_png',
                    'Transparent PNG; WebP keeps transparency at a fraction of the size.'
                );
            }
            if ($ext === 'gif' && substr_count($bytes, "\x00\x21\xF9\x04") > 1) {
                $issues[] = $this->issue(
                    'info',
                    'animated_gif',
                    'Animated GIF; converted formats may only keep the first frame unless animated conversion is '
                        . 'enabled.'
                );
            }
        }

        return ['file' => $file, 'issues' => $issues];
    }

    /**
     * Head.
     *
     * @param string $path
     * @param int $length
     * @return string
     */
    private function head(string $path, int $length): string
    {
        try {
            return substr($this->mediaFileInfo->media()->readFile($path), 0, $length);
        } catch (Throwable $e) {
            // An unreadable file has no header to check.
            $this->logger->warning('Hyvä image check: could not read a file.', ['path' => $path, 'exception' => $e]);
            return '';
        }
    }

    /**
     * IHDR colour type 4 (grey + alpha) or 6 (RGBA).
     *
     * @param string $bytes
     * @return bool
     */
    private function pngHasAlpha(string $bytes): bool
    {
        return strlen($bytes) > 25 && in_array(ord($bytes[25]), [4, 6], true);
    }

    /**
     * Issue.
     *
     * @param string $severity
     * @param string $code
     * @param string $message
     * @return array
     * @phpstan-return array{severity: string, code: string, message: string}
     */
    private function issue(string $severity, string $code, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }
}
