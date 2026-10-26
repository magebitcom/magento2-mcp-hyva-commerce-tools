<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.update` MCP tool.
 */
class MenuUpdate extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.update';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_update';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_save';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param MenuGatewayInterface $menuGateway
     * @param ContentService $contentService
     * @param DraftEditor $draftEditor
     * @param LiveSettings $liveSettings
     * @param StoreScopeGuard $storeScopeGuard
     * @param MenuItemBuilder $menuItemBuilder
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly ContentService $contentService,
        private readonly DraftEditor $draftEditor,
        private readonly LiveSettings $liveSettings,
        private readonly StoreScopeGuard $storeScopeGuard,
        private readonly MenuItemBuilder $menuItemBuilder
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Update Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update a menu: settings (title, identifier, store_ids, is_active, preview_url_key) apply IMMEDIATELY, '
            . 'and those that change the live storefront (active flag, store views, identifier; title of an active '
            . 'menu) need "confirm": true, otherwise nothing is written and the reply lists what would change. '
            . 'preview_url_key only sets the page the editor previews the menu on and needs no confirm. '
            . 'Structure '
            . 'edits (operations, same as hyva_cms.draft.update: '
            . 'add/remove/move/set_field/set_fields/replace_node/replace_text) are saved as a DRAFT; removing or '
            . 'replacing an item with children also needs "confirm": true (the reply lists those items). Prefer '
            . 'hyva_menu.item.* for single items.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->object('settings', fn (ObjectBuilder $o) => $o
                ->string('title', fn (StringBuilder $s) => $s->minLength(2))
                ->string('identifier', fn (StringBuilder $s) => $s->pattern('^[a-z0-9_]+$'))
                ->array('store_ids', fn (ArrayBuilder $a) => $a->ofIntegers()->minItems(1))
                ->boolean('is_active', fn (BooleanBuilder $b) => $b)
                ->string('preview_url_key', fn (StringBuilder $s) => $s)
                ->description('Menu settings, applied immediately. Live changes need confirm.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Apply settings that change the live '
                . 'storefront or operations that remove items with children, after reviewing the preview.'))
            ->rawProperty('operations', ContentSchema::OPERATIONS)
            ->string('version_name', fn (StringBuilder $s) => $s->maxLength(100))
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
        $settings = $this->arrayArg($arguments, 'settings');
        $operations = $this->arrayArg($arguments, 'operations');
        if ($settings === [] && $operations === []) {
            return $this->softError('Nothing to update: pass settings and/or operations.');
        }
        if (is_array($settings['store_ids'] ?? null)) {
            $this->storeScopeGuard->assertAssignable(
                'menu #' . $menuId,
                Cast::intList($menu['store_ids'] ?? []),
                Cast::intList($settings['store_ids'])
            );
        }
        $live = $this->liveSettings->changes($menu, $settings);
        $applied = null;
        $removed = [];
        if ($operations !== []) {
            $document = $this->contentService->getWorkingDocument(ContentLocator::TYPE_MENU, $menuId);
            $applied = $this->draftEditor->apply($document, $operations);
            $removed = array_map([$this->menuItemBuilder, 'describe'], $applied['dropped']);
        }
        if (($live !== [] || $removed !== []) && !$this->boolArg($arguments, 'confirm')) {
            $action = $live === [] ? 'remove_menu_item_with_children'
                : ($removed === [] ? 'apply_live_settings' : 'apply_live_settings_and_remove_menu_items');
            return $this->confirmationRequired($action, array_merge($live, $removed));
        }
        $result = ['id' => $menuId];
        if ($applied !== null) {
            $result['draft'] = $this->contentService->saveDraft(
                ContentLocator::TYPE_MENU,
                $menuId,
                $applied['document'],
                $this->stringArg($arguments, 'version_name'),
                $this->stringArg($arguments, 'base_version_uid')
            );
            $result['applied_operations'] = $applied['applied'];
            $result['published'] = false;
        }
        if ($settings !== []) {
            $stringKeyed = [];
            foreach ($settings as $key => $value) {
                $stringKeyed[(string) $key] = $value;
            }
            $result['settings_changed'] = $this->menuGateway->update($menuId, $stringKeyed);
        }
        return $this->jsonResult(
            $result,
            ['id' => $menuId, 'operations' => count($operations), 'settings' => array_keys($settings)]
        );
    }
}
