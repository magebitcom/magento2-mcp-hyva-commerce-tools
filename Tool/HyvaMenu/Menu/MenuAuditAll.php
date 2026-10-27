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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.audit_all` MCP tool.
 */
class MenuAuditAll extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.audit_all';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_audit_all';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param MenuGatewayInterface $menuGateway
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly ContentScopeFilter $scopeFilter
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit All Hyvä Menus';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Validate every menu (published version when published, otherwise the draft) and report broken links, '
            . 'disabled categories/products, menus not assigned to any store view or placement, never-published '
            . 'menus and menus not used anywhere. Menus outside your role\'s websites/store views are left out and '
            . 'counted in menus_hidden_by_permissions.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $menus = [];
        $totals = ['menus' => 0, 'errors' => 0, 'warnings' => 0, 'unused' => 0, 'unpublished' => 0, 'inactive' => 0];
        $scoped = $this->scopeFilter->filterByStoreIds($this->menuGateway->getList());
        foreach ($scoped['visible'] as $menu) {
            $edition = Cast::bool($menu['is_published'] ?? false) ? 'published' : 'draft';
            $result = $this->menuService->validate($menu, $edition);
            $usage = $this->menuService->findUsage(Cast::string($menu['identifier']));
            $totals['menus']++;
            $totals['errors'] += $result['counts']['error'];
            $totals['warnings'] += $result['counts']['warning'];
            $totals['unused'] += $usage['used'] ? 0 : 1;
            $totals['unpublished'] += Cast::bool($menu['is_published'] ?? false) ? 0 : 1;
            $totals['inactive'] += Cast::bool($menu['is_active'] ?? false) ? 0 : 1;
            $menus[] = [
                'id' => $menu['id'],
                'identifier' => $menu['identifier'],
                'title' => $menu['title'],
                'edition_checked' => $edition,
                'used' => $usage['used'],
                'placements' => array_values(array_unique(array_column($usage['design_config'], 'location'))),
                'counts' => $result['counts'],
                'issues' => array_slice($result['issues'], 0, 25),
            ];
        }
        return $this->jsonResult(
            $this->scopeFilter->withHidden(
                ['totals' => $totals, 'menus' => $menus],
                'menus_hidden_by_permissions',
                $scoped['hidden']
            ),
            $totals
        );
    }
}
