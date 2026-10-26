<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Migration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;

/**
 * Analyses Magento CMS content (Page Builder or plain WYSIWYG) for migration to Hyvä CMS.
 *
 * `converts_to` mirrors what Hyvä's own ElementConverter produces in "full" mode; `suggested_component`
 * is the closest Hyvä CMS component to rebuild it with by hand when the converter falls back to "html".
 */
class MigrationAnalyzer
{
    public const KIND_EMPTY = 'empty';
    public const KIND_PAGE_BUILDER = 'page_builder';
    public const KIND_WYSIWYG = 'wysiwyg';

    /**
     * Page Builder content type => [component Hyvä's converter creates, best-fit Hyvä component].
     */
    public const MAPPING = [
        'row' => ['row', 'row'],
        'column-group' => ['columns', 'columns'],
        'column-line' => ['columns', 'columns'],
        'column' => ['columns', 'columns'],
        'heading' => ['heading', 'heading'],
        'text' => ['text', 'text'],
        'image' => ['image', 'image'],
        'html' => ['html', 'html'],
        'products' => ['html', 'product_slider'],
        'buttons' => ['html', 'button'],
        'button-item' => ['html', 'button'],
        'banner' => ['html', 'banner'],
        'slider' => ['html', 'slider'],
        'slide' => ['html', 'banner'],
        'tabs' => ['html', 'accordion_list'],
        'tab-item' => ['html', 'accordion_item'],
        'video' => ['html', 'embed'],
        'map' => ['html', 'map'],
        'divider' => ['html', 'spacer'],
        'block' => ['html', 'cms_block'],
        'dynamic_block' => ['html', 'cms_block'],
    ];

    /**
     * @param ImageReferenceExtractor $imageReferenceExtractor
     */
    public function __construct(
        private readonly ImageReferenceExtractor $imageReferenceExtractor
    ) {
    }

    /**
     * Analyze.
     *
     * @param string $html
     * @return array
     * @phpstan-return array{kind: string, element_count: int, content_types: array<string, int>, sections: int,
     *     images: list<string>, buttons: int, sliders: int, banners: int, columns: int, tabs: int,
     *     html_fallbacks: array<string, int>, suggestions: list<array{content_type: string, count: int,
     *     converts_to: string, suggested_component: string}>, complexity_score: int, complexity: string}
     */
    public function analyze(string $html): array
    {
        $images = array_values(array_unique(array_column($this->imageReferenceExtractor->fromHtml($html), 'path')));
        $types = str_contains($html, 'data-content-type=') ? $this->countContentTypes($html) : [];
        $kind = match (true) {
            $types !== [] => self::KIND_PAGE_BUILDER,
            trim(strip_tags($html)) !== '' || $images !== [] => self::KIND_WYSIWYG,
            default => self::KIND_EMPTY,
        };

        $fallbacks = [];
        $suggestions = [];
        foreach ($types as $type => $count) {
            [$convertsTo, $suggested] = self::MAPPING[$type] ?? ['html', 'html'];
            if ($convertsTo === 'html' && $type !== 'html') {
                $fallbacks[$type] = $count;
            }
            $suggestions[] = [
                'content_type' => $type,
                'count' => $count,
                'converts_to' => $convertsTo,
                'suggested_component' => $suggested,
            ];
        }

        $elements = array_sum($types);
        $score = $elements + 3 * array_sum($fallbacks) + count($images) + ($kind === self::KIND_WYSIWYG ? 2 : 0);
        return [
            'kind' => $kind,
            'element_count' => $elements,
            'content_types' => $types,
            'sections' => $types['row'] ?? 0,
            'images' => $images,
            'buttons' => $types['button-item'] ?? 0,
            'sliders' => $types['slider'] ?? 0,
            'banners' => ($types['banner'] ?? 0) + ($types['slide'] ?? 0),
            'columns' => $types['column'] ?? 0,
            'tabs' => $types['tabs'] ?? 0,
            'html_fallbacks' => $fallbacks,
            'suggestions' => $suggestions,
            'complexity_score' => $score,
            'complexity' => $score === 0 ? 'none' : ($score < 10 ? 'low' : ($score < 30 ? 'medium' : 'high')),
        ];
    }

    /**
     * Count content types.
     *
     * @param string $html
     * @return array
     * @phpstan-return array<string, int>
     */
    private function countContentTypes(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $counts = [];
        $nodes = (new DOMXPath($dom))->query('//*[@data-content-type]');
        foreach ($nodes === false ? [] : $nodes as $node) {
            if ($node instanceof DOMElement) {
                $type = $node->getAttribute('data-content-type');
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
        }
        ksort($counts);
        return $counts;
    }
}
