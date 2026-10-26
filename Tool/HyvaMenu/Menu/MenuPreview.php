<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.preview` MCP tool.
 */
class MenuPreview extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.preview';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_preview';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param PreviewService $previewService
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly PreviewService $previewService
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Preview Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Preview URLs for the menu draft per store view (no admin login needed; uses the menu preview_url_key '
            . 'page, or an empty preview page) and the admin editor URL.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->integer('version_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $menu = $this->menuService->locate($arguments);
        $menuId = Cast::int($menu['id']);
        $preview = $this->previewService->getPreview(
            ContentLocator::TYPE_MENU,
            $menuId,
            $this->intArg($arguments, 'store_id'),
            $this->intArg($arguments, 'version_id')
        );
        return $this->jsonResult(
            [
                'id' => $menuId,
                'identifier' => $menu['identifier'],
                'preview_url_key' => $menu['preview_url_key'],
            ] + $preview,
            ['id' => $menuId]
        );
    }
}
