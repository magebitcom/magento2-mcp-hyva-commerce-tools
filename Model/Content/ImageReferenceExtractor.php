<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Api\ImageAssetPrefetchInterface;

/**
 * Finds media image references in Hyvä CMS component trees and in plain HTML.
 * Paths are returned relative to pub/media (e.g. "wysiwyg/banner.jpg").
 */
class ImageReferenceExtractor
{
    private const IMAGE_EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|bmp|tiff?';

    /**
     * @param ComponentTree $componentTree
     * @param ImageAssetPathResolverInterface $assetPathResolver
     */
    public function __construct(
        private readonly ComponentTree $componentTree,
        private readonly ImageAssetPathResolverInterface $assetPathResolver
    ) {
    }

    /**
     * Image references in a content document: structured image fields and images in HTML fields.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array{path: string, alt: string|null, has_alt: bool, uid: string|null,
     *     component: string|null, field: string, kind: string}>
     */
    public function fromDocument(array $document): array
    {
        $references = [];
        $this->componentTree->walk($document, function (array $node) use (&$references): void {
            $uid = is_string($node['uid'] ?? null) ? $node['uid'] : null;
            $component = is_string($node['component'] ?? null) ? $node['component'] : null;
            foreach ($node as $field => $value) {
                if (in_array($field, ['uid', 'component', 'componentPath', 'children'], true)) {
                    continue;
                }
                foreach ($this->fromValue($value) as $found) {
                    $references[] = [
                        'path' => $found['path'],
                        'alt' => $found['alt'],
                        'has_alt' => $found['alt'] !== null && trim($found['alt']) !== '',
                        'uid' => $uid,
                        'component' => $component,
                        'field' => $field,
                        'kind' => $found['kind'],
                    ];
                }
            }
        });
        return $references;
    }

    /**
     * Image references in an HTML/WYSIWYG/Page Builder string.
     *
     * @param string $html
     * @return array
     * @phpstan-return list<array{path: string, alt: string|null, kind: string}>
     */
    public function fromHtml(string $html): array
    {
        if ($html === '') {
            return [];
        }
        $references = [];

        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $src = $this->attribute($tag, 'src');
                $path = $src !== null ? $this->normalizePath($src) : null;
                if ($path !== null) {
                    $references[] = ['path' => $path, 'alt' => $this->attribute($tag, 'alt'), 'kind' => 'html_img'];
                }
            }
        }

        $directive = '/\{\{media\s+url\s*=\s*(?:&quot;|["\'])?([^"\'}&\s]+)/i';
        if (preg_match_all($directive, $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $path = $this->normalizePath($raw);
                if ($path !== null && !$this->containsPath($references, $path)) {
                    $references[] = ['path' => $path, 'alt' => null, 'kind' => 'media_directive'];
                }
            }
        }

        // Hyvä CMS 1.3 rich text: {{hyva_image id="12" src="wysiwyg/a.jpg" ...}}; the id wins when it resolves.
        if (preg_match_all('/\{\{hyva_image\b[^}]*\}\}/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $tag = html_entity_decode($tag, ENT_QUOTES | ENT_HTML5);
                $id = $this->attribute($tag, 'id');
                $resolved = $id !== null
                    && ctype_digit($id) ? $this->assetPathResolver->resolvePathById((int) $id) : null;
                $src = $this->attribute($tag, 'src');
                $path = $this->normalizePath((string) ($resolved ?? $src ?? ''));
                if ($path !== null && !$this->containsPath($references, $path)) {
                    $references[] = ['path' => $path, 'alt' => $this->attribute($tag, 'alt'), 'kind' => 'hyva_image'];
                }
            }
        }

        $backgrounds = '/url\(\s*(?:&quot;|["\'])?([^"\')&]+\.(?:' . self::IMAGE_EXTENSIONS . '))/i';
        if (preg_match_all($backgrounds, $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $path = $this->normalizePath($raw);
                if ($path !== null && !$this->containsPath($references, $path)) {
                    $references[] = ['path' => $path, 'alt' => null, 'kind' => 'css_background'];
                }
            }
        }

        return $references;
    }

    /**
     * Convert a media URL, `/media/...` path, `{{media}}` value or relative path to a pub/media-relative path.
     *
     * @param string $value
     * @return string|null
     */
    public function normalizePath(string $value): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES));
        if ($value === '' || str_starts_with($value, 'data:')) {
            return null;
        }
        $value = (string) preg_replace('/[?#].*$/', '', $value);
        if (preg_match('#/media/(.+)$#', $value, $match)) {
            $value = $match[1];
        } elseif (preg_match('#^https?://#i', $value)) {
            return null;
        }
        $value = ltrim($value, '/');
        if (str_starts_with($value, 'pub/media/')) {
            $value = substr($value, strlen('pub/media/'));
        }
        if (preg_match('/\.(?:' . self::IMAGE_EXTENSIONS . ')$/i', $value) !== 1) {
            return null;
        }
        $value = rawurldecode($value);
        if (in_array('..', explode('/', $value), true) || str_contains($value, '\\') || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * From value.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<array{path: string, alt: string|null, kind: string}>
     */
    private function fromValue(mixed $value): array
    {
        if (is_string($value)) {
            return (str_contains($value, '<img') || str_contains($value, '{{media') || str_contains($value, 'url('))
                ? $this->fromHtml($value)
                : [];
        }
        if (!is_array($value)) {
            return [];
        }
        if (isset($value['src']) && is_string($value['src'])) {
            $path = $this->imageFieldPath($value);
            if ($path !== null) {
                $alt = isset($value['alt']) && is_scalar($value['alt']) ? (string) $value['alt'] : null;
                return [['path' => $path, 'alt' => $alt, 'kind' => 'image_field']];
            }
        }
        $found = [];
        foreach ($value as $item) {
            foreach ($this->fromValue($item) as $reference) {
                $found[] = $reference;
            }
        }
        return $found;
    }

    /**
     * Attribute.
     *
     * @param string $tag
     * @param string $name
     * @return string|null
     */
    private function attribute(string $tag, string $name): ?string
    {
        $pattern = '/\s' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
        if (preg_match($pattern, $tag, $match) !== 1) {
            return null;
        }
        return html_entity_decode(
            $match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')),
            ENT_QUOTES
        );
    }

    /**
     * Contains path.
     *
     * @param array $references
     * @param string $path
     * @return bool
     * @phpstan-param list<array{path: string, alt: string|null, kind: string}> $references
     */
    private function containsPath(array $references, string $path): bool
    {
        foreach ($references as $reference) {
            if ($reference['path'] === $path) {
                return true;
            }
        }
        return false;
    }

    /**
     * Media path an image field renders.
     *
     * Its Media Gallery asset (imageOptions.id) when that resolves, as Hyvä's Element::resolveImageSrc() does,
     * else imageOptions.path, else src.
     *
     * @param array $value Image field value.
     * @return string|null
     * @phpstan-param array<mixed> $value
     */
    public function imageFieldPath(array $value): ?string
    {
        $options = is_array($value['imageOptions'] ?? null) ? $value['imageOptions'] : [];
        $id = $options['id'] ?? null;
        if (is_numeric($id) && (int) $id > 0) {
            $resolved = $this->assetPathResolver->resolvePathById((int) $id);
            if ($resolved !== null && $resolved !== '') {
                return $this->normalizePath($resolved);
            }
        }
        foreach ([$options['path'] ?? null, $value['src'] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $path = $this->normalizePath($candidate);
                if ($path !== null) {
                    return $path;
                }
            }
        }
        return null;
    }

    /**
     * Resolve every Media Gallery asset id in the documents with batched queries before extracting from them.
     *
     * @param array $documents
     * @return void
     * @phpstan-param iterable<mixed> $documents
     */
    public function prefetchAssetIds(iterable $documents): void
    {
        if (!$this->assetPathResolver instanceof ImageAssetPrefetchInterface) {
            return;
        }
        $ids = [];
        foreach ($documents as $document) {
            $this->collectAssetIds($document, $ids);
        }
        if ($ids !== []) {
            $this->assetPathResolver->prefetchPathsByIds(array_keys($ids));
        }
    }

    /**
     * Collect imageOptions.id and {{hyva_image id}} values.
     *
     * @param mixed $value
     * @param array $ids
     * @return void
     * @phpstan-param array<int, true> $ids
     */
    private function collectAssetIds(mixed $value, array &$ids): void
    {
        if (is_string($value)) {
            $pattern = '/hyva_image[^}]*?\bid=(?:&quot;|["\'])?(\d+)/i';
            if (str_contains($value, 'hyva_image') && preg_match_all($pattern, $value, $m)) {
                foreach ($m[1] as $id) {
                    $ids[(int) $id] = true;
                }
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        $id = is_array($value['imageOptions'] ?? null) ? ($value['imageOptions']['id'] ?? null) : null;
        if (is_numeric($id) && (int) $id > 0) {
            $ids[(int) $id] = true;
        }
        foreach ($value as $item) {
            $this->collectAssetIds($item, $ids);
        }
    }
}
