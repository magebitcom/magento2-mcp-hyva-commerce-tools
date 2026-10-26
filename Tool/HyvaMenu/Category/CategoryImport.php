<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Category;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuEditTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.category.import` MCP tool.
 */
class CategoryImport extends AbstractMenuEditTool
{
    public const TOOL_NAME = 'hyva_menu.category.import';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_category_import';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Import Categories into Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Import the category tree under root_category_id into the menu DRAFT as editable items (nested or '
            . 'flat) or as one dynamic category-tree component. Categories already linked in the menu are skipped; '
            . 'inactive and "not in menu" categories are left out unless included. dry_run (or '
            . 'hyva_menu.category.sync_preview) shows what would be created, skipped and flagged without saving. '
            . 'Never publishes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->integer('root_category_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->required()
                ->description('Import the children of this category.'))
            ->integer('depth', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(5)
                ->description('Levels below the root; default 2.'))
            ->boolean('include_inactive', fn (BooleanBuilder $b) => $b->description('Default false.'))
            ->boolean('include_hidden', fn (BooleanBuilder $b) => $b
                ->description('Include categories with "Include in Menu" = No. Default false.'))
            ->string('mode', fn (StringBuilder $s) => $s
                ->enum(['nested', 'flat', 'dynamic'])
                ->description(
                    'nested = editable items mirroring the tree (default); flat = one level; dynamic = one category '
                        . 'tree component that follows the catalog.'
                ))
            ->string('parent_uid', fn (StringBuilder $s) => $s->minLength(1))
            ->string('root', fn (StringBuilder $s) => $s
                ->enum(array_keys(self::ROOT_FILTERS))
                ->description('When no parent_uid; default all.'))
            ->boolean('dry_run', fn (BooleanBuilder $b) => $b->description('Preview only.'))
            ->string('base_version_uid', fn (StringBuilder $s) => $s->description(
                'Optional: draft version uid you read (draft.version.uid, or version_uid of your last write). The '
                . 'save is refused (status 409) when the draft changed since you read it.'
            ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $menu = $this->menuService->locate($arguments);
        $menuId = Cast::int($menu['id']);
        $storeId = $this->intArg(
            $arguments,
            'store_id'
        ) ?? $this->menuService->lookupStoreId(Cast::intList($menu['store_ids'] ?? []));
        $rootCategoryId = $this->requireInt($arguments, 'root_category_id');
        $mode = $this->stringArg($arguments, 'mode', 'nested') ?? 'nested';
        $document = $this->contentService->getWorkingDocument(ContentLocator::TYPE_MENU, $menuId);
        $plan = $this->menuService->planCategoryImport(
            $document,
            $rootCategoryId,
            $this->intArg($arguments, 'depth', 2) ?? 2,
            $storeId,
            $this->boolArg($arguments, 'include_inactive'),
            $this->boolArg($arguments, 'include_hidden')
        );
        $parents = $this->resolveParents(
            $document,
            $this->stringArg($arguments, 'parent_uid'),
            $this->stringArg($arguments, 'root', 'all') ?? 'all'
        );
        $summary = [
            'to_create' => count($plan['create']),
            'already_in_menu' => count($plan['skip_existing']),
            'inactive' => count($plan['inactive']),
            'hidden' => count($plan['hidden']),
            'missing_url_rewrites' => count($plan['missing_url_rewrites']),
            'duplicate_labels' => count($plan['duplicate_labels']),
        ];
        $preview = [
            'id' => $menuId,
            'store_id' => $storeId,
            'mode' => $mode,
            'targets' => $parents,
            'summary' => $summary,
            'items_to_create' => $plan['create'],
            'already_in_menu' => $plan['skip_existing'],
            'inactive_categories' => $plan['inactive'],
            'hidden_categories' => $plan['hidden'],
            'missing_url_rewrites' => $plan['missing_url_rewrites'],
            'duplicate_labels' => $plan['duplicate_labels'],
            'expected_structure' => $plan['tree'],
        ];
        if (in_array('menu_footer_columns', array_column($parents, 'component'), true) && $mode !== 'dynamic') {
            $preview['warnings'][] = $mode === 'flat'
                ? 'Footer columns cannot link: every category becomes its own column named after it, holding a link'
                    . ' to the category.'
                : 'Footer columns cannot link: each top-level category becomes a column named after it, holding a'
                    . ' link to the category followed by its subcategories.';
        }
        if ($this->isDryRun($arguments)) {
            return $this->jsonResult(['dry_run' => true] + $preview, ['id' => $menuId, 'dry_run' => true] + $summary);
        }

        if ($mode === 'dynamic') {
            $placements = [];
            foreach ($parents as $parent) {
                $placements[] = [
                    'parent_uid' => $parent['uid'],
                    'parent_component' => $parent['component'],
                    'spec' => ['type' => 'category_tree', 'value' => [$rootCategoryId]],
                ];
            }
        } else {
            $placements = $this->menuService->placements($document, $plan['tree'], $parents, $mode);
        }
        $added = 0;
        foreach ($placements as $placement) {
            $document = $this->componentTree->insertNode(
                $document,
                $this->menuItemBuilder->build($placement['parent_component'], $placement['spec']),
                $placement['parent_uid']
            );
            $added++;
        }
        if ($added === 0) {
            return $this->jsonResult(
                ['imported' => false, 'reason' => 'Every category is already in the menu.'] + $preview,
                ['id' => $menuId, 'added' => 0]
            );
        }
        $saved = $this->saveMenuDraft(
            $menuId,
            $document,
            'Categories imported (MCP)',
            $this->stringArg($arguments, 'base_version_uid')
        );
        return $this->jsonResult(
            ['imported' => true, 'items_added' => $added, 'draft' => $saved, 'published' => false] + $preview,
            ['id' => $menuId, 'added' => $added]
        );
    }

    /**
     * Is dry run.
     *
     * @param array $arguments
     * @return bool
     * @phpstan-param array<string, mixed> $arguments
     */
    protected function isDryRun(array $arguments): bool
    {
        return $this->boolArg($arguments, 'dry_run');
    }
}
