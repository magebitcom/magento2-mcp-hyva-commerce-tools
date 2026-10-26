<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit\AuditMenu;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuAuditAll;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuFindUsage;
use PHPUnit\Framework\TestCase;

/**
 * hyva_menu.audit_all, hyva_commerce.audit.menu and hyva_menu.find_usage leave out menus and content of other
 * websites' store views (finding: unscoped image/audit lists).
 */
class MenuAuditScopeTest extends TestCase
{
    use ContentScopeStub;

    public function testAuditAllListsOnlyTheRolesMenus(): void
    {
        $restricted = $this->payload($this->auditAll([1])->execute([]));
        $this->assertSame(['main', 'shared'], array_column($this->list($restricted['menus'] ?? null), 'identifier'));
        $this->assertSame(2, $this->list([$restricted['totals'] ?? null])[0]['menus'] ?? null);
        $this->assertSame(1, $restricted['menus_hidden_by_permissions'] ?? null);

        $all = $this->payload($this->auditAll(null)->execute([]));
        $this->assertSame(3, $this->list([$all['totals'] ?? null])[0]['menus'] ?? null);
        $this->assertArrayNotHasKey('menus_hidden_by_permissions', $all);
    }

    public function testTheCommerceMenuAuditListsOnlyTheRolesMenus(): void
    {
        $restricted = $this->payload($this->commerceAudit([1])->execute([]));
        $this->assertSame([1, 3], array_column($this->list($restricted['menus'] ?? null), 'id'));
        $this->assertSame(1, $restricted['menus_hidden_by_permissions'] ?? null);

        $all = $this->payload($this->commerceAudit(null)->execute([]));
        $this->assertSame([1, 2, 3], array_column($this->list($all['menus'] ?? null), 'id'));
        $this->assertArrayNotHasKey('menus_hidden_by_permissions', $all);
    }

    public function testFindUsageLeavesOutContentAndWidgetsOfOtherStoreViews(): void
    {
        $restricted = $this->payload($this->findUsage([1])->execute(['id' => 1]));
        $this->assertSame([1], array_column($this->list($restricted['hyva_cms_content'] ?? null), 'id'));
        $this->assertSame([], $restricted['widget_instances'] ?? null);
        $this->assertSame(2, $restricted['total'] ?? null);
        $this->assertTrue($restricted['used'] ?? null);
        $this->assertSame(2, $restricted['usages_hidden_by_permissions'] ?? null);

        $all = $this->payload($this->findUsage(null)->execute(['id' => 1]));
        $this->assertSame(4, $all['total'] ?? null);
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $all);
    }

    /**
     * Menu service validating every menu without issues and finding one placement per menu.
     *
     * @return MenuService
     */
    private function menuService(): MenuService
    {
        $service = $this->createMock(MenuService::class);
        $service->method('validate')->willReturn(['issues' => [], 'counts' => ['error' => 0, 'warning' => 0]]);
        $service->method('getAssignments')->willReturn([]);
        $service->method('locate')->willReturn(['id' => 1, 'identifier' => 'main']);
        $service->method('findUsage')->willReturn([
            'identifier' => 'main',
            'used' => true,
            'total' => 4,
            'design_config' => [['location' => 'header', 'path' => 'p', 'scope' => 'default', 'scope_id' => 0]],
            'hyva_cms_content' => [['entity_type' => 'cms_page', 'id' => 1], ['entity_type' => 'cms_page', 'id' => 2]],
            'magento_cms_content' => [],
            'widget_instances' => [['instance_id' => 4, 'title' => 'w', 'theme_id' => 1, 'store_ids' => '2']],
            'layout_and_templates' => [],
        ]);
        return $service;
    }

    /**
     * Gateway with a menu of store view 1, one of store view 2 and one of all store views.
     *
     * @return MenuGatewayInterface
     */
    private function gateway(): MenuGatewayInterface
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturn([
            ['id' => 1, 'title' => 'Main', 'identifier' => 'main', 'is_active' => true, 'store_ids' => ['1']],
            ['id' => 2, 'title' => 'Other', 'identifier' => 'other', 'is_active' => true, 'store_ids' => ['2']],
            ['id' => 3, 'title' => 'Shared', 'identifier' => 'shared', 'is_active' => true, 'store_ids' => ['0']],
        ]);
        return $gateway;
    }

    /**
     * Available features.
     *
     * @return FeatureCheckerInterface
     */
    private function features(): FeatureCheckerInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return $features;
    }

    /**
     * Audit all tool.
     *
     * @param array|null $allowedStoreIds
     * @return MenuAuditAll
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function auditAll(?array $allowedStoreIds): MenuAuditAll
    {
        return new MenuAuditAll(
            $this->features(),
            $this->menuService(),
            $this->gateway(),
            $this->contentScope($allowedStoreIds)
        );
    }

    /**
     * Commerce menu audit tool.
     *
     * @param array|null $allowedStoreIds
     * @return AuditMenu
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function commerceAudit(?array $allowedStoreIds): AuditMenu
    {
        $auditor = $this->createMock(MediaAuditor::class);
        $auditor->method('audit')->willReturn(['scope' => 'menu', 'images_checked' => 0, 'counts' => [],
            'config_issues' => [], 'images' => []]);
        return new AuditMenu(
            $this->features(),
            $this->gateway(),
            $this->menuService(),
            $auditor,
            $this->contentScope($allowedStoreIds)
        );
    }

    /**
     * Find usage tool; page 1 is store view 1's, page 2 store view 2's.
     *
     * @param array|null $allowedStoreIds
     * @return MenuFindUsage
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function findUsage(?array $allowedStoreIds): MenuFindUsage
    {
        return new MenuFindUsage(
            $this->features(),
            $this->menuService(),
            $this->contentScope($allowedStoreIds, ['cms_page' => [1 => [1], 2 => [2]]])
        );
    }

    /**
     * Decoded payload of a tool result.
     *
     * @param ToolResultInterface $result
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        return $payload;
    }

    /**
     * A payload list.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        $this->assertIsArray($value);
        return array_values($value);
    }
}
