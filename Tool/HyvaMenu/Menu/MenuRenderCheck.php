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
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.render_check` MCP tool.
 */
class MenuRenderCheck extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.render_check';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_render_check';

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
        return 'Check Hyvä Menu Rendering';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Render the PUBLISHED menu the way the storefront does (frontend area, per store view) and report '
            . 'whether it renders without errors and with content: html size, number of links, renderer message. '
            . 'Empty output '

                . 'or an exception is a failure (e.g. inactive, unpublished, not assigned to that store, broken '
                    . 'component).';
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
        $storeId = $this->intArg($arguments, 'store_id');
        // A restricted role renders only in store views it may read: an explicit one outside it is refused, the
        // menu's own are narrowed to it, and the fallback is its first store view instead of the default one.
        $storeIds = $storeId !== null ? [$this->menuService->resolveImportStoreId($storeId, [])] : array_values(
            array_filter(
                Cast::intList($menu['store_ids'] ?? []),
                fn (int $id): bool => $id > 0 && $this->storeScopeGuard->allowsRead([$id])
            )
        );
        if ($storeIds === []) {
            $storeIds = [$this->menuService->resolveImportStoreId(null, [])];
        }
        $checks = [];
        foreach ($storeIds as $id) {
            $render = $this->menuGateway->render(Cast::string($menu['identifier']), $id);
            $html = $render['html'];
            $text = trim((string) preg_replace(['#<(script|style)\b[^>]*>.*?</\1>#is', '/\s+/'], ['', ' '], $html));
            $checks[] = [
                'store_id' => $id,
                'passed' => $render['error'] === null && trim(strip_tags($html, '<a><img>')) !== '',
                'error' => $render['error'],
                'message' => $render['message'] !== null ? strip_tags($render['message']) : null,
                'html_bytes' => strlen($html),
                'links' => (int) preg_match_all('/<a\b/i', $html),
                'excerpt' => mb_substr(trim(strip_tags($text)), 0, 300),
            ];
        }
        $passed = array_filter($checks, static fn (array $c): bool => $c['passed']) === $checks;
        return $this->jsonResult(
            ['id' => $menu['id'], 'identifier' => $menu['identifier'], 'passed' => $passed, 'checks' => $checks],
            ['id' => Cast::int($menu['id']), 'passed' => $passed]
        );
    }
}
