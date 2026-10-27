<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.validate` MCP tool.
 */
class MenuValidate extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.validate';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_validate';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Validate Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Validate one menu (draft by default, or the published version): broken/disabled categories, missing '
            . 'or disabled products, missing CMS pages, malformed URLs, empty or duplicate labels, mobile menu '
            . 'depth, nesting limits, empty columns and rich content blocks, images without alt text, store-view '
            . 'mismatches, unassigned menus and menus assigned on the storefront while inactive/unpublished/empty.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->string('edition', fn (StringBuilder $s) => $s
                ->enum(['draft', 'published'])
                ->description('Default draft.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $menu = $this->menuService->locate($arguments);
        $result = $this->menuService->validate($menu, $this->stringArg($arguments, 'edition', 'draft') ?? 'draft');
        return $this->jsonResult(
            [
                'id' => $menu['id'],
                'identifier' => $menu['identifier'],
                'valid' => $result['counts']['error'] === 0,
            ] + $result,
            ['id' => Cast::int($menu['id'])] + $result['counts']
        );
    }
}
