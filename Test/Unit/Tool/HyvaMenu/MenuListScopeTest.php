<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuList;
use PHPUnit\Framework\TestCase;

/**
 * hyva_menu.list hides menus of store views outside the caller's restriction.
 */
class MenuListScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testOnlyMenusOfTheRolesStoreViewsAreListed(): void
    {
        $this->assertSame(['main', 'shared'], $this->identifiers([1]));
        $this->assertSame(['main', 'other', 'shared'], $this->identifiers(null));
    }

    /**
     * Listed menu identifiers for a caller restricted to the store views (null = unrestricted).
     *
     * @param array|null $allowedStoreIds
     * @return array
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-return list<mixed>
     */
    private function identifiers(?array $allowedStoreIds): array
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->method('getAssignments')->willReturn([]);
        $menus->method('filterAssignments')->willReturn(['visible' => [], 'hidden' => 0]);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturn([
            ['id' => 1, 'title' => 'Main', 'identifier' => 'main', 'is_active' => true, 'store_ids' => [1]],
            ['id' => 2, 'title' => 'Other', 'identifier' => 'other', 'is_active' => true, 'store_ids' => [2]],
            ['id' => 3, 'title' => 'Shared', 'identifier' => 'shared', 'is_active' => true, 'store_ids' => [0]],
        ]);
        $result = (new MenuList($features, $menus, $gateway, $this->storeScope($allowedStoreIds)))->execute([]);
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        $items = $payload['items'] ?? null;
        $this->assertIsArray($items);
        return array_column($items, 'identifier');
    }
}
