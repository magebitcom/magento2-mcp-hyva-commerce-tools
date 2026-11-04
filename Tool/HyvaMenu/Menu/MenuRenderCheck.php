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
            . 'Empty output or an exception is a failure (e.g. inactive, unpublished, not assigned to that store, '
            . 'broken component); for empty output without an error, message names the reason.';
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
        $storeId = $this->intArg($arguments, 'store_id');
        $menu = $this->menuService->locate($this->lookupArguments($arguments, $storeId));
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
            $passed = $render['error'] === null && trim(strip_tags($html, '<a><img>')) !== '';
            $message = $render['message'] !== null ? strip_tags($render['message']) : null;
            if ($message === null && !$passed && $render['error'] === null) {
                $message = $this->emptyRenderReason($menu, $id);
            }
            $checks[] = [
                'store_id' => $id,
                'passed' => $passed,
                'error' => $render['error'],
                'message' => $message,
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

    /**
     * Lookup arguments: by identifier, a menu not assigned to store_id is found in any store view (T-135).
     *
     * The identifier lookup is store-filtered, so it would say "does not exist" for a menu that only is not
     * assigned to that store view; the check then names that reason, as it does for a lookup by id.
     * Identifiers are unique per store view only, so several menus can match: the first one (lowest id) the
     * caller may read is taken; when it may read none, the lowest id is looked up and refused as before (L1).
     *
     * @param array $arguments
     * @param int|null $storeId
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<string, mixed>
     */
    private function lookupArguments(array $arguments, ?int $storeId): array
    {
        $identifier = $arguments['identifier'] ?? null;
        if ($storeId === null || $this->intArg($arguments, 'id') !== null || !is_string($identifier)
            || $identifier === '' || $this->menuGateway->findIdByIdentifier($identifier, $storeId) !== null
        ) {
            return $arguments;
        }
        unset($arguments['store_id']);
        $matches = array_values(array_filter(
            $this->menuGateway->getList(['search' => $identifier]),
            static fn (array $menu): bool => strcasecmp(Cast::string($menu['identifier'] ?? ''), $identifier) === 0
        ));
        if ($matches === []) {
            return $arguments;
        }
        usort($matches, static fn (array $a, array $b): int => Cast::int($a['id'] ?? 0) <=> Cast::int($b['id'] ?? 0));
        $menu = $matches[0];
        foreach ($matches as $match) {
            if ($this->storeScopeGuard->allowsRead(Cast::intList($match['store_ids'] ?? []))) {
                $menu = $match;
                break;
            }
        }
        unset($arguments['identifier']);
        return ['id' => Cast::int($menu['id'] ?? 0)] + $arguments;
    }

    /**
     * Why Hyvä rendered nothing without an error, from the menu state (T-114).
     *
     * @param array $menu Menu row from the gateway.
     * @param int $storeId
     * @return string
     * @phpstan-param array<string, mixed> $menu
     */
    private function emptyRenderReason(array $menu, int $storeId): string
    {
        $storeIds = Cast::intList($menu['store_ids'] ?? []);
        return match (true) {
            !Cast::bool($menu['is_active'] ?? false) => 'Nothing rendered: the menu is inactive. Publish it with '
                . 'hyva_menu_publish (it also activates the menu).',
            !Cast::bool($menu['is_published'] ?? false) => 'Nothing rendered: the menu is not published. Publish '
                . 'it with hyva_menu_publish.',
            !in_array(0, $storeIds, true) && !in_array($storeId, $storeIds, true) => sprintf(
                'Nothing rendered: the menu is not assigned to store view %d.',
                $storeId
            ),
            default => 'The published menu rendered no content (no links or images). Check its items with '
                . 'hyva_menu_validate.',
        };
    }
}
