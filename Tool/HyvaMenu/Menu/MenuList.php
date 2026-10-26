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
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.list` MCP tool.
 */
class MenuList extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_list';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param MenuGatewayInterface $menuGateway
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly StoreScopeGuard $storeScopeGuard
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä Menus';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List menus with store views, active flag, draft/published state and where each is assigned '
            . '(header/footer design config). Filter by store view, active state, assignment or search text.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->boolean('is_active', fn (BooleanBuilder $b) => $b)
            ->string('placement', fn (StringBuilder $s) => $s
                ->enum(['header', 'footer', 'unassigned'])
                ->description('Filter by design-config assignment.'))
            ->string('search', fn (StringBuilder $s) => $s->minLength(1)->description('Title or identifier substring.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $filters = [
            'store_id' => $this->intArg($arguments, 'store_id'),
            'is_active' => array_key_exists('is_active', $arguments) ? $this->boolArg($arguments, 'is_active') : null,
            'search' => $this->stringArg($arguments, 'search'),
        ];
        $placement = $this->stringArg($arguments, 'placement');
        $items = [];
        foreach ($this->menuGateway->getList($filters) as $menu) {
            // Menus of store views outside the caller's website/store view restriction are not listed.
            if (!$this->storeScopeGuard->allowsRead(Cast::intList($menu['store_ids'] ?? []))) {
                continue;
            }
            $assignments = $this->menuService->getAssignments(Cast::string($menu['identifier'] ?? ''));
            $locations = array_values(array_unique(array_column($assignments, 'location')));
            if ($placement === 'unassigned' ? $locations !== [] : (
                    $placement !== null && !in_array($placement, $locations, true)
                )
            ) {
                continue;
            }
            // Placements name every location (like `used`); the per-scope rows are those the role may open.
            $scoped = $this->menuService->filterAssignments($assignments);
            $items[] = $menu + ['placements' => $locations, 'assignments' => $scoped['visible']]
                + ($scoped['hidden'] > 0 ? ['assignments_hidden_by_permissions' => $scoped['hidden']] : []);
        }
        return $this->jsonResult(['total' => count($items), 'items' => $items], ['total' => count($items)]);
    }
}
