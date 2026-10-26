<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\Mcp\Api\LoggerInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Throwable;

/**
 * File facts for media paths relative to pub/media.
 */
class MediaFileInfo
{
    /**
     * @var ?ReadInterface
     */
    private ?ReadInterface $mediaDirectory = null;

    /**
     * @param Filesystem $filesystem
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Exists.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return $path !== '' && !str_contains($path, '..') && $this->media()->isFile($path);
    }

    /**
     * Describe.
     *
     * @param string $path
     * @return array
     * @phpstan-return array{path: string, exists: bool, size: int|null, width: int|null, height: int|null,
     *     mime: string|null, modified_at: string|null, extension: string}
     */
    public function describe(string $path): array
    {
        $info = [
            'path' => $path,
            'exists' => $this->exists($path),
            'size' => null,
            'width' => null,
            'height' => null,
            'mime' => null,
            'modified_at' => null,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ];
        if (!$info['exists']) {
            return $info;
        }
        $absolute = $this->media()->getAbsolutePath($path);
        $stat = $this->media()->stat($path);
        $info['size'] = isset($stat['size']) ? (int) $stat['size'] : null;
        $info['modified_at'] = isset($stat['mtime']) ? gmdate('Y-m-d H:i:s', (int) $stat['mtime']) : null;
        try {
            $size = $info['extension'] === 'svg' ? false : getimagesize($absolute);
        } catch (Throwable $e) {
            // No dimensions for a file PHP cannot read as an image.
            $this->logger->warning('Hyvä media info: could not read image size.', [
                'path' => $absolute,
                'exception' => $e,
            ]);
            $size = false;
        }
        if (is_array($size)) {
            $info['width'] = (int) $size[0];
            $info['height'] = (int) $size[1];
            $info['mime'] = $size['mime'];
        } elseif ($info['extension'] === 'svg') {
            $info['mime'] = 'image/svg+xml';
        }
        return $info;
    }

    /**
     * Files under a media directory, recursively (relative paths).
     *
     * @param string $directory
     * @param int $limit
     * @return array
     * @phpstan-return list<string>
     */
    public function listFiles(string $directory, int $limit = 20000): array
    {
        $files = [];
        $pending = [trim($directory, '/')];
        $media = $this->media();
        while ($pending !== [] && count($files) < $limit) {
            $current = (string) array_pop($pending);
            try {
                if (!$media->isDirectory($current)) {
                    continue;
                }
                foreach ($media->read($current) as $entry) {
                    if ($media->isDirectory($entry)) {
                        $pending[] = $entry;
                    } elseif (count($files) < $limit) {
                        $files[] = $entry;
                    }
                }
            } catch (Throwable $e) {
                // An unreadable folder is skipped; the listing goes on.
                $this->logger->warning('Hyvä media info: could not read a folder.', ['exception' => $e]);
                continue;
            }
        }
        return $files;
    }

    /**
     * Media.
     *
     * @return ReadInterface
     */
    public function media(): ReadInterface
    {
        return $this->mediaDirectory ??= $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
    }
}
