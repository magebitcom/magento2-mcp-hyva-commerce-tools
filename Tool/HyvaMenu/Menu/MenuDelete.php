<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\InUseDeleteGate;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Throwable;

/**
 * `hyva_menu.delete` MCP tool: confirm-gated delete of one menu.
 *
 * Needs the tool's own resource ("Delete Hyvä menus via MCP", granted to no role by default) and the Menu Builder
 * delete permission the admin delete action uses (Hyvä's menu delete verb as well); the core checks both. The store
 * scope write rule applies: every store view the menu is assigned to must be the role's. A menu still in use is
 * deleted only with "force": true next to "confirm": true (InUseDeleteGate).
 *
 * Pending scheduled items of the menu (T-68) are listed in the preview and cancelled after the delete: Hyvä has no
 * FK or observer for them, and an item whose menu is gone can never publish.
 */
class MenuDelete extends AbstractMenuTool
{
    use InUseDeleteGate;

    public const TOOL_NAME = 'hyva_menu.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_delete';
    /** Hyva\MenuBuilder\Controller\Adminhtml\Menu\Delete::ADMIN_RESOURCE */
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_delete';
    public const IS_WRITE = true;

    /**
     * What deleting a menu in use does to its placements.
     */
    private const IN_USE_CONSEQUENCE = 'a placement that names a deleted menu renders nothing.';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param MenuGatewayInterface $menuGateway
     * @param DeletionUsage $deletionUsage
     * @param ScheduleService $scheduleService
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly DeletionUsage $deletionUsage,
        private readonly ScheduleService $scheduleService
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
            . 'store views and where it is still used (details.usage lists, as hyva_menu_find_usage does: '
            . 'design_config = header/footer design configuration, hyva_cms_content and magento_cms_content = menu '
            . 'widgets in Hyvä CMS and Magento content, widget_instances = widget instances, layout_and_templates = '
            . 'layout and templates). A menu in use is deleted only with "force": true as well as "confirm": true '
            . '(a placement that names a deleted menu renders nothing; the result then says "forced": true and '
            . 'repeats the usage); tell the user where it is used before confirming. Pending scheduled release items '
            . 'of the menu are listed in the preview (details.scheduled_items) and cancelled with the delete '
            . '(scheduled_items_cancelled). Needs the MCP permission for this '
            . 'tool plus the admin delete permission ' . self::UNDERLYING_ACL_RESOURCE . '. A role restricted to '
            . 'some websites can delete only a menu whose store views are all its own. To remove single items use '
            . 'hyva_menu_item_remove; to hide the menu set settings.is_active=false with hyva_menu_update.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->deleteInputSchema();
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
        $scheduled = $this->pendingScheduledItems($menuId);
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(
                'delete_menu',
                [$summary],
                ['in_use' => $inUse, 'force_required' => $inUse, 'usage' => $usage]
                    + ($scheduled !== [] ? ['scheduled_items' => $scheduled] : []),
                null,
                'Deleting removes the menu from every store view listed, with its drafts and version history; it '
                    . 'cannot be undone (hyva_cms_export keeps a copy).'
                    . ($scheduled !== [] ? sprintf(
                        ' The menu has %d pending scheduled release item(s) (details.scheduled_items); deleting '
                            . 'cancels them, and a release left without pending items is cancelled as well.',
                        count($scheduled)
                    ) : '')
                    . ($inUse ? $this->inUseHint(self::IN_USE_CONSEQUENCE) : '')
            );
        }
        if ($inUse && !$this->boolArg($arguments, 'force')) {
            return $this->inUseRefusal(
                sprintf('Menu "%s" (#%d)', $summary['identifier'], $menuId),
                $usage,
                self::IN_USE_CONSEQUENCE
            );
        }
        $this->menuGateway->delete($menuId);
        $audit = ['entity_type' => 'menu', 'id' => $menuId, 'deleted' => true, 'in_use' => $inUse];
        $cancelled = $this->cancelScheduledItems($scheduled);
        if (isset($cancelled['scheduled_items_cancelled'])) {
            $audit['scheduled_items_cancelled'] = $cancelled['scheduled_items_cancelled'];
        }
        if (!$inUse) {
            return $this->jsonResult(['deleted' => true] + $summary + $cancelled, $audit);
        }
        return $this->jsonResult(
            ['deleted' => true] + $summary + $cancelled + $this->forcedDeleteDetails($usage),
            $audit + ['forced' => true]
        );
    }

    /**
     * Pending items of pending releases that publish this menu.
     *
     * @param int $menuId
     * @return array
     * @phpstan-return list<array{item_id: int, release_id: int, release_name: string, time_scheduled: string}>
     */
    private function pendingScheduledItems(int $menuId): array
    {
        $items = [];
        foreach ($this->scheduleService->getItems(
            ['status' => 'pending', 'entity_type' => ContentLocator::TYPE_MENU, 'entity_id' => $menuId]
        ) as $row) {
            $items[] = [
                'item_id' => $row['item_id'],
                'release_id' => $row['release_id'],
                'release_name' => $row['release_name'],
                'time_scheduled' => $row['time_scheduled'],
            ];
        }
        return $items;
    }

    /**
     * Cancel the deleted menu's pending scheduled items; the menu is gone either way, so a failure is reported.
     *
     * @param array $scheduled
     * @return array
     * @phpstan-param list<array{item_id: int, release_id: int, release_name: string,
     *     time_scheduled: string}> $scheduled
     * @phpstan-return array<string, mixed>
     */
    private function cancelScheduledItems(array $scheduled): array
    {
        if ($scheduled === []) {
            return [];
        }
        try {
            $result = $this->scheduleService->cancel(array_column($scheduled, 'item_id'));
        } catch (Throwable $e) {
            return [
                'scheduled_items_cancelled' => [],
                'warning' => sprintf(
                    'The menu was deleted, but its pending scheduled items %s could not be cancelled (%s); cancel '
                        . 'them with hyva_cms_schedule_cancel.',
                    implode(', ', array_map(
                        static fn (int $id): string => '#' . $id,
                        array_column($scheduled, 'item_id')
                    )),
                    $e->getMessage()
                ),
            ];
        }
        return [
            'scheduled_items_cancelled' => $result['cancelled_items'],
            'scheduled_releases_cancelled' => $result['cancelled_releases'],
        ];
    }
}
