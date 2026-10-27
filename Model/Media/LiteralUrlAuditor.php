<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Finds images in Hyvä CMS content that point at a file path instead of a Media Gallery asset id.
 *
 * Hyvä 1.4 renders the asset an image's imageOptions.id resolves to, so moving or renaming the file keeps working;
 * a bare path breaks when the file moves. Categories:
 * - literal_fixable: the path is a gallery asset; saving the draft through MCP stores the id.
 * - literal_not_in_gallery: the file is not in media_gallery_asset (run media-gallery:sync or upload it).
 * - stale_asset_id: the id no longer resolves; Hyvä falls back to the stored path.
 * - richtext_literal: <img> / {{media url}} / {{hyva_image}} without id inside rich text or HTML fields.
 */
class LiteralUrlAuditor
{
    /**
     * @param ContentScanner $contentScanner
     * @param ComponentTree $componentTree
     * @param ImageReferenceExtractor $extractor
     * @param ImageAssetPathResolverInterface $assetPathResolver
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly ContentScanner $contentScanner,
        private readonly ComponentTree $componentTree,
        private readonly ImageReferenceExtractor $extractor,
        private readonly ImageAssetPathResolverInterface $assetPathResolver,
        private readonly ContentScopeFilter $scopeFilter
    ) {
    }

    /**
     * Audit.
     *
     * Documents of content outside the caller's websites/store views are skipped and counted in
     * documents_hidden_by_permissions.
     *
     * @param array $types
     * @param int $limit Max findings listed.
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-return array{documents_checked: int, counts: array<string, int>,
     *     findings: list<array<string, mixed>>, documents_hidden_by_permissions?: int}
     */
    public function audit(array $types = ContentLocator::TYPES, int $limit = 200): array
    {
        $scoped = $this->scopeFilter->filter($this->contentScanner->getDocuments($types));
        $documents = $scoped['visible'];
        $this->extractor->prefetchAssetIds(array_column($documents, 'document'));
        $counts = ['id_backed' => 0, 'literal_fixable' => 0, 'literal_not_in_gallery' => 0, 'stale_asset_id' => 0,
            'richtext_literal' => 0];
        $findings = [];
        foreach ($documents as $entry) {
            if ($entry['document'] === null) {
                continue;
            }
            $this->componentTree->walk($entry['document'], function (array $node) use (
                &$counts,
                &$findings,
                $entry
            ): void {
                foreach ($node as $field => $value) {
                    if (in_array($field, ['uid', 'component', 'componentPath', 'children'], true)) {
                        continue;
                    }
                    foreach ($this->classify($value) as $finding) {
                        $counts[$finding['category']]++;
                        if ($finding['category'] !== 'id_backed') {
                            $findings[] = [
                                'entity_type' => $entry['entity_type'],
                                'id' => $entry['id'],
                                'identifier' => $entry['identifier'],
                                'uid' => Cast::string($node['uid'] ?? ''),
                                'component' => Cast::string($node['component'] ?? ''),
                                'field' => (string) $field,
                            ] + $finding;
                        }
                    }
                }
            });
        }
        $report = [
            'documents_checked' => count($documents),
            'counts' => $counts,
            'findings' => array_slice($findings, 0, $limit),
        ];
        if ($this->scopeFilter->isRestricted()) {
            $report['documents_hidden_by_permissions'] = $scoped['hidden'];
        }
        return $report;
    }

    /**
     * Classify the image references in one field value.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<array{category: string, path: string|null, asset_id?: int|null}>
     */
    public function classify(mixed $value): array
    {
        if (is_string($value)) {
            return $this->classifyText($value);
        }
        if (!is_array($value)) {
            return [];
        }
        if (is_string($value['src'] ?? null) && $value['src'] !== '') {
            $options = is_array($value['imageOptions'] ?? null) ? $value['imageOptions'] : [];
            $id = is_numeric($options['id'] ?? null) ? (int) $options['id'] : 0;
            $path = $this->extractor->normalizePath(Cast::string($options['path'] ?? $value['src']));
            if ($id > 0) {
                $resolved = $this->assetPathResolver->resolvePathById($id);
                return [$resolved !== null && $resolved !== ''
                    ? ['category' => 'id_backed', 'path' => $path, 'asset_id' => $id]
                    : ['category' => 'stale_asset_id', 'path' => $path, 'asset_id' => $id]];
            }
            $assetId = $path !== null ? $this->assetPathResolver->resolveIdByPath($path) : null;
            return [$assetId !== null
                ? ['category' => 'literal_fixable', 'path' => $path, 'asset_id' => $assetId]
                : ['category' => 'literal_not_in_gallery', 'path' => $path, 'asset_id' => null]];
        }
        $found = [];
        foreach ($value as $item) {
            foreach ($this->classify($item) as $finding) {
                $found[] = $finding;
            }
        }
        return $found;
    }

    /**
     * Rich text / HTML: images without an asset id.
     *
     * @param string $text
     * @return array
     * @phpstan-return list<array{category: string, path: string|null}>
     */
    private function classifyText(string $text): array
    {
        $found = [];
        foreach ($this->extractor->fromHtml($text) as $reference) {
            if ($reference['kind'] !== 'hyva_image') {
                $found[] = ['category' => 'richtext_literal', 'path' => $reference['path']];
            }
        }
        // Judge each {{hyva_image}} directive on its own id: one id-backed image must not hide a literal one.
        preg_match_all('/\{\{hyva_image\b.*?\}\}/is', $text, $matches);
        foreach ($matches[0] as $directive) {
            if (preg_match('/\bid=(?:&quot;|["\'])?\d+/i', $directive)) {
                continue;
            }
            foreach ($this->extractor->fromHtml($directive) as $reference) {
                $found[] = ['category' => 'richtext_literal', 'path' => $reference['path']];
            }
        }
        return $found;
    }
}
