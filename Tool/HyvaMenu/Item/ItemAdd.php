<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Item;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuEditTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.item.add` MCP tool.
 */
class ItemAdd extends AbstractMenuEditTool
{
    public const TOOL_NAME = 'hyva_menu.item.add';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_item_add';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Add Hyvä Menu Item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add an item to the menu DRAFT: a category, product, CMS page, Magento route or custom URL link, a '
            . 'text heading, a dynamic category tree or a rich content block (promo banner). Inserted under '
            . 'parent_uid, or at the top level of every root matching "root" (all, desktop, mobile, footer). '
            . 'Children may be nested. Never publishes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->rawProperty(
                'item',
                [
                    'type' => 'object',
                    'required' => ['type'],
                    'description' => '{label, type: ' . implode(
                        '|',
                        MenuItemBuilder::ITEM_TYPES
                    ) . ', value (category id | SKU | CMS page identifier | URL | route | category ids for '
                        . 'category_tree), open_in_new_tab?, style?: {background_color, text_color, font_weight}, '
                        . 'children?: [item], components? (type=content)}',
                    'properties' => ['type' => ['type' => 'string', 'enum' => MenuItemBuilder::ITEM_TYPES]],
                ],
                true
            )
            ->string('parent_uid', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Insert under this item/root uid (see hyva_menu.get structure).'))
            ->string('root', fn (StringBuilder $s) => $s
                ->enum(array_keys(self::ROOT_FILTERS))
                ->description('When no parent_uid: which root components get the item. Default all.'))
            ->integer('position', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Zero-based position among siblings; default last.'))
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
        $menu = $this->menuService->locateForWrite($arguments);
        $menuId = Cast::int($menu['id']);
        $document = $this->contentService->getWorkingDocument(ContentLocator::TYPE_MENU, $menuId);
        $spec = $this->arrayArg($arguments, 'item');
        $added = [];
        $warnings = [];
        foreach ($this->resolveParents(
            $document,
            $this->stringArg($arguments, 'parent_uid'),
            $this->stringArg($arguments, 'root', 'all') ?? 'all'
        ) as $parent) {
            $node = $this->menuItemBuilder->build($parent['component'], $spec);
            if ($this->menuItemBuilder->wrapsLinkInFooterColumn($parent['component'], $spec)) {
                $warnings[] = sprintf(
                    'Footer columns cannot link: a new column "%s" was created in %s holding the link. To use an'
                    . ' existing column instead, remove this one and add the item with parent_uid set to that column.',
                    $this->menuItemBuilder->labelOf($node),
                    $parent['uid']
                );
            }
            $document = $this->componentTree->insertNode(
                $document,
                $node,
                $parent['uid'],
                $this->intArg($arguments, 'position')
            );
            $added[] = [
                'parent_uid' => $parent['uid'],
                'parent_component' => $parent['component'],
                'item' => $this->menuItemBuilder->describe($node),
            ];
        }
        $saved = $this->saveMenuDraft(
            $menuId,
            $document,
            'Item added (MCP)',
            $this->stringArg($arguments, 'base_version_uid')
        );
        return $this->jsonResult(
            ['id' => $menuId, 'added' => $added, 'warnings' => $warnings, 'draft' => $saved, 'published' => false],
            ['id' => $menuId, 'added' => count($added)]
        );
    }
}
