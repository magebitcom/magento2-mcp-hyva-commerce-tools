<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Menu;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for tools that edit menu items in the menu draft.
 */
abstract class AbstractMenuEditTool extends AbstractMenuTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_save';
    public const IS_WRITE = true;
    public const ROOT_FILTERS = [
        'all' => MenuItemBuilder::ROOT_COMPONENTS,
        'desktop' => ['desktop_menu_columns', 'desktop_menu_drilldown'],
        'mobile' => ['mobile_menu'],
        'footer' => ['menu_footer_columns'],
    ];

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param MenuItemBuilder $menuItemBuilder
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        protected readonly ContentService $contentService,
        protected readonly ComponentTree $componentTree,
        protected readonly MenuItemBuilder $menuItemBuilder
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * Parent nodes to insert into: the given parent_uid, or every root component matching the root filter.
     *
     * @param array $document
     * @param string|null $parentUid
     * @param string $root all|desktop|mobile|footer
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array{uid: string, component: string}>
     */
    protected function resolveParents(array $document, ?string $parentUid, string $root): array
    {
        if ($parentUid !== null) {
            $parent = $this->componentTree->findNode($document, $parentUid);
            if ($parent === null) {
                throw new LocalizedException(__('Parent item "%1" was not found in the menu draft.', $parentUid));
            }
            return [['uid' => $parentUid, 'component' => Cast::string($parent['component'] ?? '')]];
        }
        $allowed = self::ROOT_FILTERS[$root] ?? self::ROOT_FILTERS['all'];
        $parents = [];
        foreach ($this->componentTree->getRootNodes($document) as $node) {
            $component = Cast::string($node['component'] ?? '');
            if (in_array($component, $allowed, true) && is_string($node['uid'] ?? null)) {
                $parents[] = ['uid' => $node['uid'], 'component' => $component];
            }
        }
        if ($parents === []) {
            throw new LocalizedException(
                __('The menu has no "%1" root component. Add one (hyva_menu.update) or pass parent_uid.', $root)
            );
        }
        return $parents;
    }

    /**
     * Save menu draft.
     *
     * @param int $menuId
     * @param array $document
     * @param string $versionName
     * @param string|null $baseVersionUid
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array{version_id: int|null, version_uid: string, warnings: list<array<string, mixed>>}
     */
    protected function saveMenuDraft(
        int $menuId,
        array $document,
        string $versionName,
        ?string $baseVersionUid = null
    ): array {
        return $this->contentService->saveDraft(
            ContentLocator::TYPE_MENU,
            $menuId,
            $document,
            $versionName,
            $baseVersionUid
        );
    }
}
