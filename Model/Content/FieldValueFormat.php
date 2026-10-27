<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

/**
 * Value shape each Hyvä CMS field type is stored as, as the Liveview editor writes it
 * (liveview-editor templates/liveview/field-types/*.phtml and the page/js/*-handler.phtml scripts)
 * and Block\Element reads it back.
 */
class FieldValueFormat
{
    private const FORMATS = [
        'link' => [
            'format' => 'object {type, value, label?, open_in_new_tab?, prefix?, suffix?, placeholder?}',
            'example' => [
                'type' => 'custom_url',
                'value' => 'sale',
                'label' => 'Shop the sale',
                'open_in_new_tab' => false,
                'prefix' => 'STORE_URL',
                'suffix' => '',
                'placeholder' => '',
            ],
            'notes' => 'type is one of category, product, cms_page, custom_url, magento. value: category = category id '
                . '(int), product = SKU (not the id), cms_page = page identifier (not the id), custom_url = URL or '
                . 'path, magento = route path for getUrl(). prefix only applies to custom_url: "" (absolute URL), '
                . '"STORE_URL" (value is a store-relative path, no http), "mailto:" or "tel:". suffix is appended to '
                . 'the final URL (e.g. "#reviews"). label is the link text: always set it, because Hyvä falls back to '
                    . 'placeholder only when '
                . 'the label key is missing - an empty "" label shows the URL. '
                . 'Keys like "url" are ignored: {"url": "/sale"} renders an empty link.',
        ],
        'image' => [
            'format' => 'object {src, alt?, width?, height?}',
            'example' => ['src' => 'wysiwyg/banner.jpg', 'alt' => 'Spring sale', 'width' => '1200', 'height' => '600'],
            'notes' => 'src is relative to pub/media (a full URL also works). width/height are strings. The editor '
                . 'also stores preview_url, classes, displaySidebar and imageOptions; omit them. A stale '
                . 'imageOptions.id overrides src with that media-gallery asset.',
        ],
        'richtext' => [
            'format' => 'HTML string',
            'example' => '<p>Up to <strong>50% off</strong> outdoor gear.</p>',
            'notes' => 'Magento directives such as {{store url=""}} are processed.',
        ],
        'html' => [
            'format' => 'string (raw HTML)',
            'example' => '<div class="note">Free delivery over 50 EUR</div>',
            'notes' => 'Stored as a plain string, not as {html: ...}.',
        ],
        'url' => ['format' => 'string (URL)', 'example' => 'https://www.youtube.com/embed/xyz', 'notes' => null],
        'products' => [
            'format' => 'array of {sku, sort_order, id?, name?}',
            'example' => [['sku' => '24-MB01', 'sort_order' => 1, 'name' => 'Joust Duffle Bag']],
            'notes' => 'Rendering uses sku and sort_order; id and name only label the editor list. When set, '
                . 'products take precedence over category_ids.',
        ],
        'category_selector' => [
            'format' => 'array of {id, name?, path?, image?}',
            'example' => [['id' => 6, 'name' => 'Sale']],
            'notes' => 'Renderers also accept a comma-separated id string ("6,7"), but the editor only displays '
                . 'the array form.',
        ],
        'widget' => [
            'format' => 'string (widget directive)',
            'example' => '{{widget type="Magento\\Cms\\Block\\Widget\\Block"'
                . ' template="widget/static_block/default.phtml" block_id="footer_links"}}',
            'notes' => null,
        ],
        'variant' => [
            'format' => 'string (one option value)',
            'example' => 'default',
            'notes' => 'Empty falls back to the first variant.',
        ],
        'select' => [
            'format' => 'string (one option value)',
            'example' => 'default',
            'notes' => 'Always a string, even for numeric options: the editor compares with ===.',
        ],
        'searchable_select' => ['format' => 'string (option id)', 'example' => 'main-menu', 'notes' => null],
        'option_list' => [
            'format' => 'array of {label, value, default?}',
            'example' => [
                ['label' => 'Small', 'value' => 's', 'default' => true],
                ['label' => 'Large', 'value' => 'l'],
            ],
            'notes' => 'attributes.default_selection says how many rows may carry default: true - "single" (one) or '
                . '"multiple"; without it, leave default out.',
        ],
        'multiselect' => ['format' => 'array of option value strings', 'example' => ['a', 'b'], 'notes' => null],
        'color' => ['format' => 'string (CSS colour)', 'example' => '#1f2937', 'notes' => null],
        'text-align' => [
            'format' => 'string: text-left | text-center | text-right',
            'example' => 'text-center',
            'notes' => 'Tailwind class names, not left/center/right.',
        ],
        'range' => ['format' => 'number within the field min/max', 'example' => 4, 'notes' => null],
        'number' => ['format' => 'number', 'example' => 3, 'notes' => null],
        'boolean' => ['format' => 'true | false', 'example' => true, 'notes' => null],
        'text' => ['format' => 'string', 'example' => 'Black Friday', 'notes' => null],
        'textarea' => ['format' => 'string (line breaks allowed)', 'example' => 'Free delivery', 'notes' => null],
        'date' => ['format' => 'string Y-m-d', 'example' => '2026-11-27', 'notes' => null],
        'datetime' => ['format' => 'string Y-m-d\TH:i', 'example' => '2026-11-27T09:00', 'notes' => null],
        'preset' => [
            'format' => 'no value of its own',
            'example' => null,
            'notes' => 'Picking a preset in the editor fills sibling fields; set those fields directly instead.',
        ],
        'category_importer' => [
            'format' => 'no value of its own',
            'example' => null,
            'notes' => 'The editor uses it to create child items; add children (or use hyva_menu.category.import) '
                . 'instead.',
        ],
    ];

    /**
     * Value format of one field type, or null when the type is unknown (e.g. a custom field type).
     *
     * @param string $type
     * @return array|null
     * @phpstan-return array{format: string, example?: mixed, notes?: string}|null
     */
    public function get(string $type): ?array
    {
        $format = self::FORMATS[$type] ?? null;
        if ($format === null) {
            return null;
        }
        return array_filter($format, static fn ($value): bool => $value !== null);
    }
}
