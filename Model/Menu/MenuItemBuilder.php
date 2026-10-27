<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Menu;

use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\LocalizedException;

/**
 * Builds Hyvä Menu Builder component nodes from simple item specs and describes them back.
 *
 * Item spec: {label?, type: category|product|cms_page|custom_url|magento|text|category_tree|content,
 *             value?, open_in_new_tab?, style?: {background_color, text_color, font_weight},
 *             children?: [item spec], components?: [component spec] (type=content)}
 */
class MenuItemBuilder
{
    public const ROOT_COMPONENTS = [
        'desktop_menu_columns',
        'desktop_menu_drilldown',
        'mobile_menu',
        'menu_footer_columns',
    ];
    public const ITEM_COMPONENTS = ['hyva_menu_item_level_0', 'hyva_menu_item', 'menu_footer_columns_item'];
    public const LINK_TYPES = ['category', 'product', 'cms_page', 'custom_url', 'magento'];
    public const ITEM_TYPES = [
        'category',
        'product',
        'cms_page',
        'custom_url',
        'magento',
        'text',
        'category_tree',
        'content',
    ];
    /**
     * Components with a link / text_only / style fields; footer columns and containers only have a label.
     */
    public const LINKABLE_COMPONENTS = ['hyva_menu_item_level_0', 'hyva_menu_item'];
    private const STYLE_FIELDS = ['background_color', 'text_color', 'font_weight'];

    /**
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     */
    public function __construct(
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor
    ) {
    }

    /**
     * Build.
     *
     * @param string $parentComponent Component the item is inserted into.
     * @param array $spec
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<mixed> $spec
     * @phpstan-return array<string, mixed>
     */
    public function build(string $parentComponent, array $spec): array
    {
        $type = Cast::string($spec['type'] ?? 'custom_url');
        if (!in_array($type, self::ITEM_TYPES, true)) {
            throw new LocalizedException(
                __('Unknown menu item type "%1". Use one of: %2.', $type, implode(', ', self::ITEM_TYPES))
            );
        }
        $label = Cast::string($spec['label'] ?? '');

        if ($type === 'category_tree') {
            $ids = is_array($spec['value'] ?? null) ? Cast::intList($spec['value']) : Cast::intList(
                explode(',', Cast::string($spec['value'] ?? ''))
            );
            return $this->componentTree->createNode('hyva_menu_category_tree', ['category_ids' => implode(',', $ids)]);
        }
        if ($type === 'content') {
            $children = $this->draftEditor->buildNodes(
                is_array($spec['components'] ?? null) ? $spec['components'] : []
            );
            return $this->componentTree->createNode('hyva_menu_content_container', ['label' => $label], $children);
        }

        $component = $this->itemComponentFor($parentComponent);
        if ($component === 'menu_footer_columns_item') {
            $node = $this->componentTree->createNode($component, ['label' => $label]);
            if ($this->wrapsLinkInFooterColumn($parentComponent, $spec)) {
                // Footer columns cannot link: the link goes inside the new column, so it is never dropped.
                $linkSpec = $spec;
                unset($linkSpec['children']);
                $spec['children'] = array_merge(
                    [$linkSpec],
                    is_array($spec['children'] ?? null) ? array_values($spec['children']) : []
                );
            }
        } else {
            $fields = $type === 'text'
                ? ['text_only' => true, 'label' => $label]
                : ['text_only' => false, 'label' => $label, 'link' => $this->link(
                    $type,
                    $spec['value'] ?? null,
                    $label,
                    Cast::bool($spec['open_in_new_tab'] ?? false),
                    Cast::string($spec['placeholder'] ?? '')
                )];
            $style = is_array($spec['style'] ?? null) ? $spec['style'] : [];
            foreach (self::STYLE_FIELDS as $field) {
                if (isset($style[$field]) && is_scalar($style[$field])) {
                    $fields[$field] = (string) $style[$field];
                }
            }
            $node = $this->componentTree->createNode($component, $fields);
        }

        $children = [];
        foreach (is_array($spec['children'] ?? null) ? $spec['children'] : [] as $childSpec) {
            if (is_array($childSpec)) {
                $children[] = $this->build($component, $childSpec);
            }
        }
        $node['children'] = $children;
        return $node;
    }

    /**
     * Item fields a component cannot hold: footer columns and containers only have a label.
     *
     * @param string $component
     * @param array $fields Field / argument names to check.
     * @return array
     * @phpstan-param list<int|string> $fields
     * @phpstan-return list<string>
     */
    public function unsupportedFields(string $component, array $fields): array
    {
        if (in_array($component, self::LINKABLE_COMPONENTS, true)) {
            return [];
        }
        $linkFields = ['type', 'value', 'open_in_new_tab', 'text_only', 'style'];
        return array_values(array_intersect($linkFields, array_map('strval', $fields)));
    }

    /**
     * Whether build() turns a link spec into a footer column holding that link.
     *
     * @param string $parentComponent
     * @param array $spec
     * @return bool
     * @phpstan-param array<mixed> $spec
     */
    public function wrapsLinkInFooterColumn(string $parentComponent, array $spec): bool
    {
        return $this->itemComponentFor($parentComponent) === 'menu_footer_columns_item'
            && in_array(Cast::string($spec['type'] ?? 'custom_url'), self::LINK_TYPES, true);
    }

    /**
     * Hyvä CMS link field value, same shape as the Liveview link picker saves.
     *
     * @param string $type
     * @param mixed $value Category id, product SKU, CMS page identifier, URL or route.
     * @param string $label Custom link text; empty = the target's own name.
     * @param bool $openInNewTab
     * @param string $placeholder Display name of the target (category / product / page name).
     * @return array
     * @throws LocalizedException
     * @phpstan-return array<string, mixed>
     */
    public function link(
        string $type,
        mixed $value,
        string $label = '',
        bool $openInNewTab = false,
        string $placeholder = ''
    ): array {
        if (!in_array($type, self::LINK_TYPES, true)) {
            throw new LocalizedException(__('Unknown link type "%1".', $type));
        }
        if ($value === null || $value === '') {
            throw new LocalizedException(__('A %1 link needs a value.', $type));
        }
        $isUrl = $type === 'custom_url';
        $stringValue = Cast::string($value);
        return [
            'type' => $type,
            'value' => $type === 'category' ? Cast::int($value) : $stringValue,
            'label' => $label,
            'open_in_new_tab' => $openInNewTab,
            'prefix' => $isUrl && str_starts_with($stringValue, '/') ? 'STORE_URL' : '',
            'suffix' => '',
            'placeholder' => $placeholder !== '' ? $placeholder : ($label !== '' ? $label : $stringValue),
            'src' => $isUrl || $type === 'cms_page' ? $stringValue : '',
        ];
    }

    /**
     * Item component for.
     *
     * @param string $parentComponent
     * @return string
     */
    public function itemComponentFor(string $parentComponent): string
    {
        return match ($parentComponent) {
            'desktop_menu_columns' => 'hyva_menu_item_level_0',
            'menu_footer_columns' => 'menu_footer_columns_item',
            default => 'hyva_menu_item',
        };
    }

    /**
     * Readable view of a menu node and its descendants.
     *
     * @param array $node
     * @return array
     * @phpstan-param array<string, mixed> $node
     * @phpstan-return array<string, mixed>
     */
    public function describe(array $node): array
    {
        $component = Cast::string($node['component'] ?? '');
        $link = is_array($node['link'] ?? null) ? $node['link'] : null;
        $item = array_filter([
            'uid' => $node['uid'] ?? null,
            'component' => $component,
            'label' => $this->labelOf($node),
            'text_only' => isset($node['text_only']) ? Cast::bool($node['text_only']) : null,
            'link' => $link !== null ? [
                'type' => $link['type'] ?? null,
                'value' => $link['value'] ?? null,
                'open_in_new_tab' => (bool) ($link['open_in_new_tab'] ?? false),
            ] : null,
            'category_ids' => $component === 'hyva_menu_category_tree' ? ($node['category_ids'] ?? null) : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $stringKeyed = [];
                foreach ($child as $key => $value) {
                    $stringKeyed[(string) $key] = $value;
                }
                $children[] = $this->describe($stringKeyed);
            }
        }
        if ($children !== []) {
            $item['children'] = $children;
        }
        return $item;
    }

    /**
     * Visible label: own label field, link label, or link placeholder (the target name).
     *
     * @param array $node
     * @return string
     * @phpstan-param array<string, mixed> $node
     */
    public function labelOf(array $node): string
    {
        $label = trim(Cast::string($node['label'] ?? ''));
        $link = is_array($node['link'] ?? null) ? $node['link'] : [];
        if ($label !== '' && (Cast::bool($node['text_only'] ?? false) || !isset($node['link']))) {
            return $label;
        }
        foreach (['label', 'placeholder'] as $key) {
            $value = trim(Cast::string($link[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return $label;
    }
}
