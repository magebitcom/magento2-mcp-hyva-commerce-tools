<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;

/**
 * Rewrites image references inside Hyvä CMS drafts (pages, blocks, menus). Live content is never touched.
 *
 * @phpstan-import-type ImageUsage from ImageUsageIndex
 */
class ImageContentEditor
{
    /**
     * @param ImageUsageIndex $usageIndex
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param ImageReferenceExtractor $extractor
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly ImageUsageIndex $usageIndex,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly ImageReferenceExtractor $extractor,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * Hyvä entities whose draft or published content uses the image, and usages that cannot be edited as drafts.
     *
     * @param string $path
     * @param array $types cms_page|cms_block|menu
     * @param array $entityIds Restrict to these ids (empty = all).
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-param list<int> $entityIds
     * @phpstan-return array{editable: list<array{entity_type: string, id: int, identifier: string}>,
     *     not_editable: list<ImageUsage>}
     */
    public function findTargets(string $path, array $types, array $entityIds = []): array
    {
        $editable = [];
        $notEditable = [];
        foreach ($this->usageIndex->usagesOf($path) as $usage) {
            $isHyva = in_array(
                $usage['source'],
                [ImageUsageIndex::SOURCE_HYVA_CMS, ImageUsageIndex::SOURCE_MENU],
                true
            );
            if (!$isHyva) {
                $notEditable[] = $usage;
                continue;
            }
            if (!in_array($usage['entity_type'], $types, true) || (
                    $entityIds !== [] && !in_array($usage['id'], $entityIds, true)
                )
            ) {
                continue;
            }
            $editable[$usage['entity_type'] . ':' . $usage['id']] = [
                'entity_type' => $usage['entity_type'],
                'id' => $usage['id'],
                'identifier' => $usage['identifier'],
            ];
        }
        return ['editable' => array_values($editable), 'not_editable' => $notEditable];
    }

    /**
     * Apply a node rewrite to the drafts of the given entities.
     *
     * @param array $targets
     * @param callable $rewrite
     * @param string $versionName
     * @return array
     * @throws LocalizedException
     * @phpstan-param list<array{entity_type: string, id: int, identifier: string}> $targets
     * @phpstan-param callable(array<string, mixed>): array<string, mixed> $rewrite
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, changed: bool,
     *     version_id: int|null}>
     */
    public function rewrite(array $targets, callable $rewrite, string $versionName): array
    {
        $results = [];
        foreach ($targets as $target) {
            try {
                $document = $this->contentService->getWorkingDocument($target['entity_type'], $target['id']);
                $updated = $this->componentTree->mapNodes($document, $rewrite);
                $changed = $updated !== $document;
                $versionId = null;
                if ($changed) {
                    $versionId = $this->contentService->saveDraft(
                        $target['entity_type'],
                        $target['id'],
                        $updated,
                        $versionName
                    )['version_id'];
                }
            } catch (LocalizedException $e) {
                // Drafts saved before the failure stay saved: name them so the caller can review or revert them.
                $saved = array_filter($results, static fn (array $result): bool => $result['changed']);
                if ($saved === []) {
                    throw $e;
                }
                throw new LocalizedException(__(
                    '%1 (at %2 #%3). Drafts already rewritten before the failure: %4.',
                    $e->getMessage(),
                    $target['entity_type'],
                    $target['id'],
                    implode(', ', array_map(
                        static fn (array $result): string => sprintf(
                            '%s #%d (version %s)',
                            $result['entity_type'],
                            $result['id'],
                            (string) $result['version_id']
                        ),
                        $saved
                    ))
                ), $e);
            }
            $results[] = $target + ['changed' => $changed, 'version_id' => $versionId];
        }
        return $results;
    }

    /**
     * Node rewrite that swaps an image path in image fields and HTML fields.
     *
     * @param string $oldPath
     * @param string $newPath
     * @param string|null $alt New alt text for swapped image fields (null keeps it).
     * @return callable
     * @phpstan-return callable(array<string, mixed>): array<string, mixed>
     */
    public function replaceRewrite(string $oldPath, string $newPath, ?string $alt): callable
    {
        return function (array $node) use ($oldPath, $newPath, $alt): array {
            foreach ($node as $field => $value) {
                if (in_array($field, ['uid', 'component', 'componentPath', 'children'], true)) {
                    continue;
                }
                $node[$field] = $this->replaceIn($value, $oldPath, $newPath, $alt);
            }
            return $node;
        };
    }

    /**
     * Node rewrite that sets alt text on image fields and <img> tags pointing at a path.
     *
     * @param string $path
     * @param string $alt
     * @return callable
     * @phpstan-return callable(array<string, mixed>): array<string, mixed>
     */
    public function altRewrite(string $path, string $alt): callable
    {
        return function (array $node) use ($path, $alt): array {
            foreach ($node as $field => $value) {
                if (in_array($field, ['uid', 'component', 'componentPath', 'children'], true)) {
                    continue;
                }
                $node[$field] = $this->setAltIn($value, $path, $alt);
            }
            return $node;
        };
    }

    /**
     * Replace in.
     *
     * @param mixed $value
     * @param string $oldPath
     * @param string $newPath
     * @param string|null $alt
     * @return mixed
     */
    private function replaceIn(mixed $value, string $oldPath, string $newPath, ?string $alt): mixed
    {
        if (is_string($value)) {
            return str_contains($value, $oldPath) ? $this->replacePathInText($value, $oldPath, $newPath) : $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value['src'])
                && is_string($value['src'])
                && $this->extractor->imageFieldPath($value) === $oldPath
        ) {
            $value['src'] = $newPath;
            foreach (['preview_url'] as $key) {
                if (isset($value[$key]) && is_string($value[$key])) {
                    $value[$key] = str_replace($oldPath, $newPath, $value[$key]);
                }
            }
            if (is_array($value['imageOptions'] ?? null)) {
                $options = $value['imageOptions'];
                foreach (['path', 'image_url'] as $key) {
                    if (isset($options[$key]) && is_string($options[$key])) {
                        $options[$key] = str_replace($oldPath, $newPath, $options[$key]);
                    }
                }
                // Drop the old Media Gallery asset id: ContentService::saveDraft() backfills the new one from path.
                $options['path'] = $newPath;
                unset($options['id']);
                $value['imageOptions'] = $options;
            }
            if ($alt !== null) {
                $value['alt'] = $alt;
            }
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->replaceIn($item, $oldPath, $newPath, $alt);
        }
        return $value;
    }

    /**
     * Set alt in.
     *
     * @param mixed $value
     * @param string $path
     * @param string $alt
     * @return mixed
     */
    private function setAltIn(mixed $value, string $path, string $alt): mixed
    {
        if (is_string($value)) {
            if (!str_contains($value, '<img')) {
                return $value;
            }
            return (string) preg_replace_callback('/<img\b[^>]*>/i', function (array $match) use ($path, $alt): string {
                $tag = $match[0];
                if (!preg_match(
                    '/\ssrc\s*=\s*["\']([^"\']+)["\']/i',
                    $tag,
                    $src
                ) || $this->extractor->normalizePath($src[1]) !== $path
                ) {
                    return $tag;
                }
                $escaped = $this->escaper->escapeHtmlAttr($alt);
                $replaced = preg_replace('/\salt\s*=\s*(["\'])[^"\']*\1/i', ' alt="' . $escaped . '"', $tag, 1, $count);
                return $count > 0 ? (string) $replaced : (string) preg_replace(
                    '/^<img\b/i',
                    '<img alt="' . $escaped . '"',
                    $tag
                );
            }, $value);
        }
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value['src']) && is_string($value['src']) && $this->extractor->imageFieldPath($value) === $path) {
            $value['alt'] = $alt;
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->setAltIn($item, $path, $alt);
        }
        return $value;
    }

    /**
     * Replace whole references to a media path inside text/HTML, never a longer path that merely contains it.
     *
     * Matches "wysiwyg/a.jpg" in `src="/media/wysiwyg/a.jpg"`, `{{media url="wysiwyg/a.jpg"}}` or `url(wysiwyg/a.jpg)`,
     * but not in "wysiwyg/a.jpg.bak" or "other/wysiwyg/a.jpg".
     *
     * @param string $text
     * @param string $oldPath
     * @param string $newPath
     * @return string
     */
    public function replacePathInText(string $text, string $oldPath, string $newPath): string
    {
        $pattern = '#(?<=^|["\'(=\s]|/media/|url=)' . preg_quote($oldPath, '#') . '(?=$|["\')?\#\s}&])#';
        return (string) preg_replace($pattern, $newPath, $text);
    }

    /**
     * Normalise a user-supplied path (URL, /media/..., relative) or throw.
     *
     * @param string $value
     * @return string
     * @throws LocalizedException
     */
    public function normalize(string $value): string
    {
        $path = $this->extractor->normalizePath($value);
        if ($path === null) {
            throw new LocalizedException(__('"%1" is not a media image path (e.g. wysiwyg/banner.jpg).', $value));
        }
        return $path;
    }
}
