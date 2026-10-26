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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.item.update` MCP tool.
 */
class ItemUpdate extends AbstractMenuEditTool
{
    public const TOOL_NAME = 'hyva_menu.item.update';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_item_update';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Update Hyvä Menu Item';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Change one menu item in the DRAFT: label, link target (type + value), open in new tab, text-only '
            . 'heading, highlight style (background_color, text_color, font_weight), or move it (parent_uid / '
            . 'position). The category name in the catalog is not changed. Never publishes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->string('uid', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->string('label', fn (StringBuilder $s) => $s
                ->description('Custom label; empty string resets to the target name.'))
            ->string('type', fn (StringBuilder $s) => $s->enum(MenuItemBuilder::LINK_TYPES))
            ->rawProperty('value', ['type' => ['string', 'integer'], 'description' => 'New link target for type.'])
            ->boolean('open_in_new_tab', fn (BooleanBuilder $b) => $b)
            ->boolean('text_only', fn (BooleanBuilder $b) => $b)
            ->rawProperty('style', ['type' => 'object', 'description' => '{background_color, text_color, font_weight}'])
            ->string('parent_uid', fn (StringBuilder $s) => $s->minLength(1)->description('Move under this item/root.'))
            ->integer('position', fn (IntegerBuilder $i) => $i->minimum(0))
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

        $component = Cast::string($node['component'] ?? '');
        $linkArguments = $this->menuItemBuilder->unsupportedFields($component, array_keys($arguments));
        if ($linkArguments !== []) {
            return $this->softError(sprintf(
                'Item "%s" is a %s, which only has a label, so %s cannot be set. Change a link inside it'
                . ' (hyva_menu.get lists the children) or add one with hyva_menu.item.add and parent_uid "%s".',
                $uid,
                $component,
                implode(', ', $linkArguments),
                $uid
            ));
        }

        $changed = [];
        $link = is_array($node['link'] ?? null) ? $node['link'] : null;
        if (isset($arguments['type']) || array_key_exists('value', $arguments)) {
            $type = $this->stringArg($arguments, 'type', Cast::string($link['type'] ?? 'custom_url')) ?? 'custom_url';
            $link = $this->menuItemBuilder->link(
                $type,
                $arguments['value'] ?? ($link['value'] ?? null),
                Cast::string($link['label'] ?? ''),
                (bool) ($link['open_in_new_tab'] ?? false)
            );
            $changed[] = 'link';
        }
        if (array_key_exists('label', $arguments)) {
            $label = is_string($arguments['label']) ? $arguments['label'] : '';
            $document = $this->componentTree->setField($document, $uid, 'label', $label);
            if ($link !== null) {
                $link['label'] = $label;
            }
            $changed[] = 'label';
        }
        if (array_key_exists('open_in_new_tab', $arguments) && $link !== null) {
            $link['open_in_new_tab'] = $this->boolArg($arguments, 'open_in_new_tab');
            $changed[] = 'open_in_new_tab';
        }
        if ($link !== null && $changed !== []) {
            $document = $this->componentTree->setField($document, $uid, 'link', $link);
        }
        if (array_key_exists('text_only', $arguments)) {
            $document = $this->componentTree->setField(
                $document,
                $uid,
                'text_only',
                $this->boolArg($arguments, 'text_only')
            );
            $changed[] = 'text_only';
        }
        foreach ($this->arrayArg($arguments, 'style') as $field => $value) {
            if (in_array($field, ['background_color', 'text_color', 'font_weight'], true) && is_scalar($value)) {
                $document = $this->componentTree->setField($document, $uid, (string) $field, (string) $value);
                $changed[] = (string) $field;
            }
        }
        $parentUid = $this->stringArg($arguments, 'parent_uid');
        $position = $this->intArg($arguments, 'position');
        if ($parentUid !== null || $position !== null) {
            $document = $this->componentTree->moveNode(
                $document,
                $uid,
                $parentUid ?? $this->componentTree->findParentUid($document, $uid),
                $position
            );
            $changed[] = 'position';
        }
        if ($changed === []) {
            return $this->softError('Nothing to change.');
        }
        $saved = $this->saveMenuDraft(
            $menuId,
            $document,
            'Item updated (MCP)',
            $this->stringArg($arguments, 'base_version_uid')
        );
        $updated = $this->componentTree->findNode($document, $uid) ?? [];
        return $this->jsonResult(
            [
                'id' => $menuId,
                'uid' => $uid,
                'changed' => array_values(array_unique($changed)),
                'item' => $this->menuItemBuilder->describe($updated),
                'draft' => $saved,
                'published' => false,
            ],
            ['id' => $menuId, 'uid' => $uid]
        );
    }
}
