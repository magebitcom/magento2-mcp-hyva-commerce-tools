<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Menu;

use Magebit\McpHyvaCommerceTools\Api\LinkTargetResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Checks one menu document for broken links, inactive targets, label problems, depth and empty structures.
 */
class MenuValidator
{
    private const DEFAULT_MOBILE_MAX_LEVEL = 4;

    /**
     * @param LinkTargetResolverInterface $linkTargetResolver
     * @param ComponentTree $componentTree
     * @param ImageReferenceExtractor $imageReferenceExtractor
     * @param MenuItemBuilder $menuItemBuilder
     */
    public function __construct(
        private readonly LinkTargetResolverInterface $linkTargetResolver,
        private readonly ComponentTree $componentTree,
        private readonly ImageReferenceExtractor $imageReferenceExtractor,
        private readonly MenuItemBuilder $menuItemBuilder
    ) {
    }

    /**
     * Validate.
     *
     * @param array $document
     * @param array $menu
     * @param int $storeId Store used for catalog lookups (first assigned store view).
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array{store_ids: list<int>, is_active: bool, is_published?: bool, assigned?: bool} $menu
     * @phpstan-return list<array{severity: string, code: string, uid: string|null, label: string|null,
     *     message: string}>
     */
    public function validate(array $document, array $menu, int $storeId = 0): array
    {
        $issues = [];
        $roots = $this->componentTree->getRootNodes($document);

        if ($roots === []) {
            $issues[] = $this->issue(
                'error',
                'empty_menu',
                null,
                null,
                'The menu has no root component (mega menu, drilldown, mobile or footer columns).'
            );
        }
        if ($menu['store_ids'] === []) {
            $issues[] = $this->issue(
                'warning',
                'unassigned_menu',
                null,
                null,
                'The menu is not assigned to any store view.'
            );
        }
        if (($menu['assigned'] ?? false) && ($roots === [] || !$menu['is_active'] || !($menu['is_published'] ?? true))
        ) {
            $issues[] = $this->issue(
                'error',
                'assigned_but_empty',
                null,
                null,
                'The menu is used on the storefront but is inactive, unpublished or empty.'
            );
        }

        foreach ($this->componentTree->validate($document) as $structural) {
            if ($structural['severity'] === 'error') {
                $issues[] = $this->issue(
                    'error',
                    $structural['code'],
                    $structural['uid'],
                    null,
                    $structural['message']
                );
            }
        }

        $links = ['category' => [], 'product' => [], 'cms_page' => []];
        $this->componentTree->walk($document, function (array $node) use (&$links): void {
            $link = is_array($node['link'] ?? null) ? $node['link'] : null;
            if ($link !== null && !Cast::bool($node['text_only'] ?? false)) {
                $type = Cast::string($link['type'] ?? '');
                if ($type === 'category') {
                    $links['category'][] = Cast::int($link['value'] ?? 0);
                } elseif ($type === 'product' || $type === 'cms_page') {
                    $links[$type][] = Cast::string($link['value'] ?? '');
                }
            }
            if (($node['component'] ?? null) === 'hyva_menu_category_tree') {
                foreach ($this->categoryIds($node['category_ids'] ?? null) as $id) {
                    $links['category'][] = $id;
                }
            }
        });
        $categories = $this->linkTargetResolver->getCategories(
            array_values(array_unique($links['category'])),
            $storeId
        );
        $products = $this->linkTargetResolver->getProducts(
            array_values(array_unique(array_filter($links['product']))),
            $storeId
        );
        $pages = $this->linkTargetResolver->getCmsPages(array_values(array_unique(array_filter($links['cms_page']))));

        foreach ($roots as $root) {
            $this->checkNode($root, 0, $root, $menu, $categories, $products, $pages, $issues);
        }

        foreach ($this->imageReferenceExtractor->fromDocument($document) as $image) {
            if (!$image['has_alt']) {
                $issues[] = $this->issue(
                    'warning',
                    'missing_alt',
                    $image['uid'],
                    null,
                    sprintf('Image %s has no alt text.', $image['path'])
                );
            }
        }

        $rank = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($issues, static fn (array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $issues;
    }

    /**
     * Check node.
     *
     * @param array $node
     * @param int $depth Item depth below the root component (root = 0).
     * @param array $root
     * @param array $menu
     * @param array $categories
     * @param array $products
     * @param array $pages
     * @param array $issues
     * @return void
     * @phpstan-param array<string, mixed> $node
     * @phpstan-param array<string, mixed> $root
     * @phpstan-param array{store_ids: list<int>, is_active: bool, is_published?: bool, assigned?: bool} $menu
     * @phpstan-param array<int, array{exists: bool, active: bool, include_in_menu: bool, name: string,
     *     has_url_rewrite: bool}> $categories
     * @phpstan-param array<string, array{exists: bool, enabled: bool, name: string}> $products
     * @phpstan-param array<string, array{exists: bool, active: bool, store_ids: list<int>}> $pages
     * @phpstan-param list<array{severity: string, code: string, uid: string|null, label: string|null,
     *     message: string}> $issues
     */
    private function checkNode(
        array $node,
        int $depth,
        array $root,
        array $menu,
        array $categories,
        array $products,
        array $pages,
        array &$issues
    ): void {
        $uid = is_string($node['uid'] ?? null) ? $node['uid'] : null;
        $component = Cast::string($node['component'] ?? '');
        $label = $this->menuItemBuilder->labelOf($node);
        $children = $this->children($node);

        if (in_array($component, MenuItemBuilder::ITEM_COMPONENTS, true)) {
            $this->checkLink($node, $uid, $label, $menu, $categories, $products, $pages, $issues);
            if ($label === '') {
                $issues[] = $this->issue(
                    Cast::bool($node['text_only'] ?? false)
                        || $component === 'menu_footer_columns_item' ? 'error' : 'warning',
                    'empty_label',
                    $uid,
                    null,
                    'Menu item has no label.'
                );
            }
            $isHeading = $component === 'menu_footer_columns_item' || (
                $component === 'hyva_menu_item_level_0' && Cast::bool($node['text_only'] ?? false)
            );
            if ($isHeading && $children === []) {
                $issues[] = $this->issue(
                    'warning',
                    'empty_column',
                    $uid,
                    $label,
                    sprintf('Column "%s" has no items.', $label)
                );
            }
            if (($root['component'] ?? null) === 'mobile_menu') {
                $maxLevel = Cast::int(
                    $root['max_level'] ?? self::DEFAULT_MOBILE_MAX_LEVEL,
                    self::DEFAULT_MOBILE_MAX_LEVEL
                );
                if ($depth > $maxLevel) {
                    $issues[] = $this->issue(
                        'warning',
                        'too_deep_mobile',
                        $uid,
                        $label,
                        sprintf('Item is %d levels deep; the mobile menu shows %d.', $depth, $maxLevel)
                    );
                }
            }
        }
        if ($component === 'hyva_menu_content_container' && $children === []) {
            $issues[] = $this->issue('warning', 'empty_content_block', $uid, $label, 'Rich content block is empty.');
        }
        if ($component === 'hyva_menu_category_tree') {
            $ids = $this->categoryIds($node['category_ids'] ?? null);
            if ($ids === []) {
                $issues[] = $this->issue(
                    'error',
                    'empty_category_tree',
                    $uid,
                    null,
                    'Category tree has no categories selected.'
                );
            }
            foreach ($ids as $id) {
                $this->checkCategory($id, $uid, 'category tree', $categories, $issues);
            }
        }

        $seen = [];
        foreach ($children as $child) {
            $childLabel = mb_strtolower($this->menuItemBuilder->labelOf($child));
            if ($childLabel !== '' && in_array(
                Cast::string($child['component'] ?? ''),
                MenuItemBuilder::ITEM_COMPONENTS,
                true
            )
            ) {
                if (isset($seen[$childLabel])) {
                    $issues[] = $this->issue(
                        'warning',
                        'duplicate_label',
                        is_string($child['uid'] ?? null) ? $child['uid'] : null,
                        $childLabel,
                        sprintf('Label "%s" appears more than once at this level.', $childLabel)
                    );
                }
                $seen[$childLabel] = true;
            }
            $this->checkNode($child, $depth + 1, $root, $menu, $categories, $products, $pages, $issues);
        }
    }

    /**
     * Check link.
     *
     * @param array $node
     * @param string|null $uid
     * @param string $label
     * @param array $menu
     * @param array $categories
     * @param array $products
     * @param array $pages
     * @param array $issues
     * @return void
     * @phpstan-param array<string, mixed> $node
     * @phpstan-param array{store_ids: list<int>, is_active: bool, is_published?: bool, assigned?: bool} $menu
     * @phpstan-param array<int, array{exists: bool, active: bool, include_in_menu: bool, name: string,
     *     has_url_rewrite: bool}> $categories
     * @phpstan-param array<string, array{exists: bool, enabled: bool, name: string}> $products
     * @phpstan-param array<string, array{exists: bool, active: bool, store_ids: list<int>}> $pages
     * @phpstan-param list<array{severity: string, code: string, uid: string|null, label: string|null,
     *     message: string}> $issues
     */
    private function checkLink(
        array $node,
        ?string $uid,
        string $label,
        array $menu,
        array $categories,
        array $products,
        array $pages,
        array &$issues
    ): void {
        if (Cast::bool($node['text_only'] ?? false) || !is_array($node['link'] ?? null)) {
            return;
        }
        $link = $node['link'];
        $type = Cast::string($link['type'] ?? '');
        $value = $link['value'] ?? null;
        switch ($type) {
            case 'category':
                $this->checkCategory(Cast::int($value), $uid, $label, $categories, $issues);
                break;
            case 'product':
                $product = $products[Cast::string($value)] ?? null;
                if ($product === null || !$product['exists']) {
                    $issues[] = $this->issue(
                        'error',
                        'missing_product',
                        $uid,
                        $label,
                        sprintf('Product SKU "%s" does not exist.', Cast::string($value))
                    );
                } elseif (!$product['enabled']) {
                    $issues[] = $this->issue(
                        'warning',
                        'disabled_product',
                        $uid,
                        $label,
                        sprintf('Product "%s" is disabled.', $product['name'])
                    );
                }
                break;
            case 'cms_page':
                $page = $pages[Cast::string($value)] ?? null;
                if ($page === null || !$page['exists']) {
                    $issues[] = $this->issue(
                        'error',
                        'missing_cms_page',
                        $uid,
                        $label,
                        sprintf('CMS page "%s" does not exist.', Cast::string($value))
                    );
                } elseif (!$page['active']) {
                    $issues[] = $this->issue(
                        'warning',
                        'inactive_cms_page',
                        $uid,
                        $label,
                        sprintf('CMS page "%s" is disabled.', Cast::string($value))
                    );
                } elseif (!in_array(0, $menu['store_ids'], true) && !in_array(0, $page['store_ids'], true)
                    && array_intersect($menu['store_ids'], $page['store_ids']) === []) {
                    $issues[] = $this->issue(
                        'warning',
                        'store_view_mismatch',
                        $uid,
                        $label,
                        sprintf('CMS page "%s" is not available in the menu\'s store views.', Cast::string($value))
                    );
                }
                break;
            case 'custom_url':
                $url = trim(Cast::string($value));
                $absolute = preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1;
                if ($url === '' || preg_match('/\s/', $url) === 1 || (
                        $absolute && filter_var(
                            $url,
                            FILTER_VALIDATE_URL
                        ) === false && !str_starts_with($url, 'mailto:') && !str_starts_with($url, 'tel:')
                    )
                ) {
                    $issues[] = $this->issue(
                        'error',
                        'broken_url',
                        $uid,
                        $label,
                        sprintf('URL "%s" is empty or malformed.', $url)
                    );
                }
                break;
            case 'magento':
                if (trim(Cast::string($value)) === '') {
                    $issues[] = $this->issue('error', 'broken_url', $uid, $label, 'Magento route link is empty.');
                }
                break;
            default:
                $issues[] = $this->issue(
                    'error',
                    'broken_link',
                    $uid,
                    $label,
                    sprintf('Link type "%s" is unknown.', $type)
                );
        }
    }

    /**
     * Check category.
     *
     * @param int $id
     * @param string|null $uid
     * @param string|null $label
     * @param array $categories
     * @param array $issues
     * @return void
     * @phpstan-param array<int, array{exists: bool, active: bool, include_in_menu: bool, name: string,
     *     has_url_rewrite: bool}> $categories
     * @phpstan-param list<array{severity: string, code: string, uid: string|null, label: string|null,
     *     message: string}> $issues
     */
    private function checkCategory(int $id, ?string $uid, ?string $label, array $categories, array &$issues): void
    {
        $category = $categories[$id] ?? null;
        if ($category === null || !$category['exists']) {
            $issues[] = $this->issue(
                'error',
                'broken_category',
                $uid,
                $label,
                sprintf('Category #%d does not exist.', $id)
            );
            return;
        }
        if (!$category['active']) {
            $issues[] = $this->issue(
                'warning',
                'inactive_category',
                $uid,
                $label,
                sprintf('Category "%s" (#%d) is disabled.', $category['name'], $id)
            );
        } elseif (!$category['has_url_rewrite']) {
            $issues[] = $this->issue(
                'warning',
                'missing_url_rewrite',
                $uid,
                $label,
                sprintf('Category "%s" (#%d) has no URL rewrite in this store.', $category['name'], $id)
            );
        }
    }

    /**
     * Category ids.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<int>
     */
    private function categoryIds(mixed $value): array
    {
        if (is_array($value)) {
            $ids = [];
            foreach ($value as $item) {
                $ids[] = is_array($item) ? Cast::int($item['id'] ?? 0) : Cast::int($item);
            }
            return array_values(array_filter($ids));
        }
        return array_values(array_filter(Cast::intList(explode(',', Cast::string($value)))));
    }

    /**
     * Children.
     *
     * @param array $node
     * @return array
     * @phpstan-param array<string, mixed> $node
     * @phpstan-return list<array<string, mixed>>
     */
    private function children(array $node): array
    {
        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $stringKeyed = [];
                foreach ($child as $key => $value) {
                    $stringKeyed[(string) $key] = $value;
                }
                $children[] = $stringKeyed;
            }
        }
        return $children;
    }

    /**
     * Issue.
     *
     * @param string $severity
     * @param string $code
     * @param string|null $uid
     * @param string|null $label
     * @param string $message
     * @return array
     * @phpstan-return array{severity: string, code: string, uid: string|null, label: string|null, message: string}
     */
    private function issue(string $severity, string $code, ?string $uid, ?string $label, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'uid' => $uid, 'label' => $label, 'message' => $message];
    }
}
