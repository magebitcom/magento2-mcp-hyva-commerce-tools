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
use Magento\Framework\Filesystem\Driver\File;
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
     * @param File $fileDriver
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
        private readonly File $fileDriver
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
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        try {
            return $this->media()->isFile($path);
        } catch (Throwable) {
            // Magento's path validator refuses the path (e.g. "-x", "a -b", control characters); its message names
            // the absolute media path, so it never reaches the client (M1, review lap 3).
            return false;
        }
    }

    /**
     * Whether a folder exists under pub/media.
     *
     * @param string $folder Relative to pub/media.
     * @return bool
     */
    public function folderExists(string $folder): bool
    {
        $folder = trim($folder, '/');
        if ($folder === '' || str_contains($folder, '..')) {
            return false;
        }
        try {
            return $this->media()->isDirectory($folder);
        } catch (Throwable) {
            // As exists(): a path the validator refuses is a folder that does not exist, never an error.
            return false;
        }
    }

    /**
     * Whether the path, with symbolic links resolved, lies outside pub/media (T-143).
     *
     * Magento's path validator normalises paths without resolving links, so a link under pub/media that points
     * elsewhere passes it. A path that does not exist yet (e.g. a new target folder) is judged by its nearest
     * existing parent. Unknown (no local media directory, a path the validator refuses) counts as inside: the
     * other checks refuse such paths on their own.
     *
     * @param string $path Relative to pub/media.
     * @return bool
     */
    public function resolvesOutsideMedia(string $path): bool
    {
        try {
            $root = $this->fileDriver->getRealPath($this->media()->getAbsolutePath());
            if (!is_string($root) || $root === '') {
                return false;
            }
            $root = rtrim($root, '/');
            $candidate = trim($path, '/');
            while (true) {
                $real = $this->fileDriver->getRealPath($this->media()->getAbsolutePath($candidate));
                if (is_string($real) && $real !== '') {
                    return $real !== $root && !str_starts_with($real, $root . '/');
                }
                if ($candidate === '') {
                    return false;
                }
                $slash = strrpos($candidate, '/');
                $candidate = $slash === false ? '' : substr($candidate, 0, $slash);
            }
        } catch (Throwable) {
            return false;
        }
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
     * File size in bytes from a stat only (no image read), null when the file is missing or unreadable.
     *
     * @param string $path
     * @return int|null
     */
    public function size(string $path): ?int
    {
        if (!$this->exists($path)) {
            return null;
        }
        try {
            $stat = $this->media()->stat($path);
        } catch (Throwable $e) {
            $this->logger->warning('Hyvä media info: could not stat a file.', ['path' => $path, 'exception' => $e]);
            return null;
        }
        return isset($stat['size']) ? (int) $stat['size'] : null;
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
