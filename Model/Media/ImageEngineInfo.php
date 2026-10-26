<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Imagick;
use Magebit\Mcp\Api\LoggerInterface;
use Throwable;

/**
 * GD / Imagick availability and supported formats, read from the PHP runtime.
 * Hyvä's PlatformChecker is avoided on purpose: it writes admin notifications as a side effect.
 */
class ImageEngineInfo
{
    private const RELEVANT_FORMATS = ['jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'svg'];

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get engines.
     *
     * @return array
     * @phpstan-return array{gd: array{available: bool, version: string|null, formats: list<string>},
     *     imagick: array{available: bool, version: string|null, formats: list<string>}}
     */
    public function getEngines(): array
    {
        return ['gd' => $this->getGdInfo(), 'imagick' => $this->getImagickInfo()];
    }

    /**
     * Supports.
     *
     * @param string $engine gd|imagick
     * @param string $format
     * @return bool
     */
    public function supports(string $engine, string $format): bool
    {
        $engines = $this->getEngines();
        $info = $engines[$engine] ?? null;
        return $info !== null && $info['available'] && in_array(strtolower($format), $info['formats'], true);
    }

    /**
     * Get gd info.
     *
     * @return array
     * @phpstan-return array{available: bool, version: string|null, formats: list<string>}
     */
    private function getGdInfo(): array
    {
        if (!function_exists('gd_info')) {
            return ['available' => false, 'version' => null, 'formats' => []];
        }
        $info = gd_info();
        $map = [
            'jpeg' => 'JPEG Support', 'png' => 'PNG Support', 'gif' => 'GIF Create Support',
            'webp' => 'WebP Support', 'avif' => 'AVIF Support', 'bmp' => 'BMP Support',
        ];
        $formats = [];
        foreach ($map as $format => $key) {
            if (!empty($info[$key])) {
                $formats[] = $format;
            }
        }
        $version = $info['GD Version'] ?? null;
        return ['available' => true, 'version' => is_string($version) ? $version : null, 'formats' => $formats];
    }

    /**
     * Get imagick info.
     *
     * @return array
     * @phpstan-return array{available: bool, version: string|null, formats: list<string>}
     */
    private function getImagickInfo(): array
    {
        if (!class_exists(Imagick::class)) {
            return ['available' => false, 'version' => null, 'formats' => []];
        }
        $formats = [];
        try {
            $supported = array_map('strtolower', Imagick::queryFormats());
            foreach (self::RELEVANT_FORMATS as $format) {
                if (in_array($format, $supported, true) || ($format === 'jpeg' && in_array('jpg', $supported, true))) {
                    $formats[] = $format;
                }
            }
            $version = Imagick::getVersion();
            $versionString = is_string($version['versionString'] ?? null) ? $version['versionString'] : null;
        } catch (Throwable $e) {
            // Imagick is installed but could not report its formats or version.
            $this->logger->warning('Hyvä image engines: Imagick did not answer.', ['exception' => $e]);
            $versionString = null;
        }
        return ['available' => true, 'version' => $versionString, 'formats' => $formats];
    }
}
