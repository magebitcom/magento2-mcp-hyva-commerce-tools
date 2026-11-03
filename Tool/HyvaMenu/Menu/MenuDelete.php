<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * `hyva_menu.delete` MCP tool: confirm-gated delete of one menu.
 *
 * Needs the tool's own resource ("Delete Hyvä menus via MCP", granted to no role by default) and the Menu Builder
 * delete permission the admin delete action uses (Hyvä's menu delete verb as well); the core checks both. The store
 * scope write rule applies: every store view the menu is assigned to must be the role's.
 */
class MenuDelete extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_delete';
    /** Hyva\MenuBuilder\Controller\Adminhtml\Menu\Delete::ADMIN_RESOURCE */
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_delete';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param MenuGatewayInterface $menuGateway
     * @param DeletionUsage $deletionUsage
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly DeletionUsage $deletionUsage
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Delete Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a menu permanently, from every store view it is assigned to, with its drafts, version history '
            . 'and Tailwind CSS. Without "confirm": true nothing is deleted and the reply previews the menu, its '
            . 'store views and where it is still used (header/footer design configuration, menu widgets in content, '
            . 'widget instances, layout and templates); a menu in use stays deletable, but say so to the user before '
            . 'confirming: a placement that names a deleted menu renders nothing. Needs the MCP permission for this '
            . 'tool plus the admin delete permission ' . self::UNDERLYING_ACL_RESOURCE . '. A role restricted to '
            . 'some websites can delete only a menu whose store views are all its own. To remove single items use '
            . 'hyva_menu_item_remove; to hide the menu set settings.is_active=false with hyva_menu_update.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Delete, after reviewing the preview. Without it nothing is deleted.'
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
        $usage = $this->deletionUsage->forMenu(Cast::string($menu['identifier']));
        $inUse = ($usage['used'] ?? false) === true;
        $summary = [
            'entity_type' => 'menu',
            'id' => $menuId,
            'identifier' => Cast::string($menu['identifier']),
            'title' => Cast::string($menu['title'] ?? ''),
            'is_active' => Cast::bool($menu['is_active'] ?? false),
            'store_ids' => Cast::intList($menu['store_ids'] ?? []),
            'in_use' => $inUse,
        ];
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(
                'delete_menu',
                [$summary],
                ['in_use' => $inUse, 'usage' => $usage],
                null,
                'Deleting removes the menu from every store view listed, with its drafts and version history; it '
                    . 'cannot be undone (hyva_cms_export keeps a copy).' . ($inUse
                    ? ' It is still in use (details.usage): those placements render nothing. Tell the user before '
                        . 'confirming.'
                    : '')
            );
        }
        $this->menuGateway->delete($menuId);
        return $this->jsonResult(
            ['deleted' => true] + $summary,
            ['entity_type' => 'menu', 'id' => $menuId, 'deleted' => true, 'in_use' => $inUse]
        );
    }
}
