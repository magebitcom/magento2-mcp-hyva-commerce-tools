<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.find_usage` MCP tool.
 */
class MenuFindUsage extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.find_usage';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_find_usage';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly ContentScopeFilter $scopeFilter
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Find Hyvä Menu Usage';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find where a menu is used: header/footer design configuration per scope, hyva_menu_widget components '
            . 'in Hyvä CMS pages/blocks/menus, hyva_cms_menu widget instances and widget directives in Magento CMS '
            . 'content, and layout XML / PHTML files that mention it. Use before deleting or renaming a menu. Content, '
            . 'widget instances and design configuration scopes outside your role\'s websites/store views are left '
            . 'out and counted in usages_hidden_by_permissions.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $menu = $this->menuService->locate($arguments);
        $usage = $this->menuService->withScopedAssignments($this->scopeFilter->filterUsageReport(
            $this->menuService->findUsage(Cast::string($menu['identifier'])),
            ['hyva_cms_content', 'magento_cms_content'],
            ['widget_instances']
        ));
        return $this->jsonResult(
            ['id' => $menu['id']] + $usage,
            ['id' => Cast::int($menu['id']), 'total' => Cast::int($usage['total'] ?? 0)]
        );
    }
}
