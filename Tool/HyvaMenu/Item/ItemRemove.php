<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Item;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuEditTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.item.remove` MCP tool.
 */
class ItemRemove extends AbstractMenuEditTool
{
    public const TOOL_NAME = 'hyva_menu.item.remove';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_item_remove';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Remove Hyvä Menu Item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove one item (and its children) from the menu DRAFT. Items with children need "confirm": true; '
            . 'without it the affected children are listed and nothing changes. Never publishes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->string('uid', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Required when the item has children.'))
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
        $uid = $this->requireString($arguments, 'uid');
        $document = $this->contentService->getWorkingDocument(ContentLocator::TYPE_MENU, $menuId);
        $node = $this->componentTree->findNode($document, $uid);
        if ($node === null) {
            return $this->softError(sprintf('Item "%s" was not found in the menu draft.', $uid));
        }
        $described = $this->menuItemBuilder->describe($node);
        if (!empty($node['children']) && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('remove_menu_item_with_children', [$described]);
        }
        $result = $this->componentTree->removeNode($document, $uid);
        $saved = $this->saveMenuDraft(
            $menuId,
            $result['document'],
            'Item removed (MCP)',
            $this->stringArg($arguments, 'base_version_uid')
        );
        return $this->jsonResult(
            ['id' => $menuId, 'removed' => $described, 'draft' => $saved, 'published' => false],
            ['id' => $menuId, 'uid' => $uid]
        );
    }
}
