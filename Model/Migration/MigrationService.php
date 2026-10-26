<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Migration;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Hyva\CmsLiveviewEditor\Model\Migration\Pagebuilder\ContentMigrator;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;

/**
 * Scans legacy (non-Hyvä) CMS content and builds Hyvä CMS documents from it with Hyvä's own Page Builder migrator.
 */
class MigrationService
{
    /**
     * @param ContentIndex $contentIndex
     * @param MigrationAnalyzer $analyzer
     * @param ContentMigrator $contentMigrator
     * @param ComponentTree $componentTree
     * @param MediaFileInfo $mediaFileInfo
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly MigrationAnalyzer $analyzer,
        private readonly ContentMigrator $contentMigrator,
        private readonly ComponentTree $componentTree,
        private readonly MediaFileInfo $mediaFileInfo
    ) {
    }

    /**
     * Pages/blocks still rendered from Magento content, plus converted drafts not yet published.
     *
     * @param array $types
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-return list<array<string, mixed>>
     */
    public function scan(array $types = [ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK]): array
    {
        $rows = [];
        foreach ($types as $type) {
            foreach ($this->contentIndex->getRows($type, true) as $row) {
                if (!$this->contentIndex->isLegacy($row)) {
                    continue;
                }
                $analysis = $this->analyzer->analyze($row['magento_content'] ?? '');
                $rows[] = [
                    'entity_type' => $type,
                    'id' => $row['id'],
                    'identifier' => $row['identifier'],
                    'title' => $row['title'],
                    'is_active' => $row['is_active'],
                    'kind' => $analysis['kind'],
                    'complexity' => $analysis['complexity'],
                    'complexity_score' => $analysis['complexity_score'],
                    'element_count' => $analysis['element_count'],
                    'html_fallbacks' => array_sum($analysis['html_fallbacks']),
                    'images' => count($analysis['images']),
                    'has_hyva_draft' => $row['has_draft'],
                    'converted_draft_waiting' => $row['has_draft'] && !$row['is_published'],
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $b['complexity_score'] <=> $a['complexity_score']);
        return $rows;
    }

    /**
     * Get magento content.
     *
     * @param string $type
     * @param int $id
     * @return string
     */
    public function getMagentoContent(string $type, int $id): string
    {
        foreach ($this->contentIndex->getRows($type, true, [$id]) as $row) {
            if ($row['id'] === $id) {
                return $row['magento_content'] ?? '';
            }
        }
        return '';
    }

    /**
     * Analyze.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @phpstan-return array{kind: string, element_count: int, content_types: array<string, int>, sections: int,
     *     images: list<string>, buttons: int, sliders: int, banners: int, columns: int, tabs: int,
     *     html_fallbacks: array<string, int>, suggestions: list<array{content_type: string, count: int,
     *     converts_to: string, suggested_component: string}>, complexity_score: int, complexity: string,
     *     missing_images: list<string>}
     */
    public function analyze(string $type, int $id): array
    {
        $analysis = $this->analyzer->analyze($this->getMagentoContent($type, $id));
        $analysis['missing_images'] = array_values(
            array_filter($analysis['images'], fn (string $path): bool => !$this->mediaFileInfo->exists($path))
        );
        return $analysis;
    }

    /**
     * Convert Magento content to a Hyvä CMS document (nothing is saved).
     *
     * @param string $type
     * @param int $id
     * @param string $mode full|partial
     * @return array
     * @phpstan-return array{document: array<string, mixed>, warnings: list<string>, analysis: array<string, mixed>}
     */
    public function convert(string $type, int $id, string $mode = 'full'): array
    {
        $html = $this->getMagentoContent($type, $id);
        $analysis = $this->analyze($type, $id);
        $migrated = $this->contentMigrator->migratePagebuilderContent($html, $mode);
        $document = $this->componentTree->normalizeDocument(['content' => $migrated['content'] ?? []], $type, $id);

        $warnings = [];
        foreach ($analysis['html_fallbacks'] as $contentType => $count) {
            $warnings[] = sprintf(
                '%d "%s" element(s) were kept as HTML components; rebuild them with "%s" for full editing.',
                $count,
                $contentType,
                MigrationAnalyzer::MAPPING[$contentType][1] ?? 'html'
            );
        }
        if ($analysis['kind'] === MigrationAnalyzer::KIND_WYSIWYG) {
            $warnings[] = 'WYSIWYG content has no Page Builder structure; it was converted into a single HTML '
                . 'component.';
        }
        foreach ($analysis['missing_images'] as $path) {
            $warnings[] = sprintf('Image "%s" is referenced but missing from pub/media.', $path);
        }
        return ['document' => $document, 'warnings' => $warnings, 'analysis' => $analysis];
    }
}
