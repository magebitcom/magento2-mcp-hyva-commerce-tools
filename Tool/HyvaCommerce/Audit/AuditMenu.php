<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_commerce.audit.menu` MCP tool.
 */
class AuditMenu extends AbstractTool
{
    public const TOOL_NAME = 'hyva_commerce.audit.menu';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_commerce_audit_menu';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuGatewayInterface $menuGateway
     * @param MenuService $menuService
     * @param MediaAuditor $auditor
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly MenuService $menuService,
        private readonly MediaAuditor $auditor,
        private readonly ContentScopeFilter $scopeFilter
    ) {
    }

    /**
     * Hyvä resource the tool is listed with.
     *
     * Menu Builder's resources only exist while the module is enabled; without it the tool stays listed under
     * Hyvä CMS access and answers that the feature is unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::MENU_BUILDER)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Hyva_Cms::cms';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Hyvä Commerce Menus';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Menu health: broken menu links, inactive categories, disabled products, empty menus, menus not '
            . 'assigned to store views or placements, plus menu image problems. Reports available=false without Hyvä '
            . 'Menu Builder. Menus outside your role\'s websites/store views are left out and counted in '
            . 'menus_hidden_by_permissions.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('max_kb', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(1000))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::MENU_BUILDER)
            );
        }
        $menus = [];
        $totals = [
            'menus' => 0,
            'errors' => 0,
            'warnings' => 0,
            'empty' => 0,
            'unassigned_store' => 0,
            'unplaced' => 0,
            'inactive' => 0,
        ];
        $codes = [];
        $scoped = $this->scopeFilter->filterByStoreIds($this->menuGateway->getList());
        foreach ($scoped['visible'] as $menu) {
            $result = $this->menuService->validate(
                $menu,
                Cast::bool($menu['is_published'] ?? false) ? 'published' : 'draft'
            );
            $placements = $this->menuService->getAssignments(Cast::string($menu['identifier'] ?? ''));
            $totals['menus']++;
            $totals['errors'] += $result['counts']['error'];
            $totals['warnings'] += $result['counts']['warning'];
            $totals['empty'] += in_array('empty_menu', array_column($result['issues'], 'code'), true) ? 1 : 0;
            $totals['unassigned_store'] += Cast::intList($menu['store_ids'] ?? []) === [] ? 1 : 0;
            $totals['unplaced'] += $placements === [] ? 1 : 0;
            $totals['inactive'] += Cast::bool($menu['is_active'] ?? false) ? 0 : 1;
            foreach ($result['issues'] as $issue) {
                $codes[$issue['code']] = ($codes[$issue['code']] ?? 0) + 1;
            }
            $menus[] = [
                'id' => $menu['id'],
                'identifier' => $menu['identifier'],
                'counts' => $result['counts'],
                'placements' => array_column($placements, 'location'),
            ];
        }
        ksort($codes);
        $media = $this->auditor->audit(
            'menu',
            $this->intArg($arguments, 'max_kb', 500) ?? 500,
            $this->intArg($arguments, 'limit', 20) ?? 20
        );
        return $this->jsonResult(
            $this->scopeFilter->withHidden(
                ['totals' => $totals, 'issue_counts' => $codes, 'menus' => $menus, 'media' => $media],
                'menus_hidden_by_permissions',
                $scoped['hidden']
            ),
            $totals
        );
    }
}
