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
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuRenderCheck;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * hyva_menu.render_check renders only in store views the caller's role may read.
 */
class MenuRenderCheckScopeTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @var list<int>
     */
    private array $rendered = [];

    public function testTheMenusStoreViewsAreNarrowedToTheRole(): void
    {
        $this->tool([1], [1, 2])->execute(['id' => 4]);
        $this->assertSame([1], $this->rendered);

        $this->rendered = [];
        $this->tool(null, [1, 2])->execute(['id' => 4]);
        $this->assertSame([1, 2], $this->rendered);
    }

    public function testAMenuOnlyInOtherStoreViewsFallsBackToTheRolesStoreView(): void
    {
        $this->tool([1], [2], 1)->execute(['id' => 4]);

        $this->assertSame([1], $this->rendered);
    }

    public function testAnExplicitStoreViewOutsideTheRoleIsRefused(): void
    {
        $result = $this->tool([1], [1, 2])->execute(['id' => 4, 'store_id' => 2]);

        $this->assertTrue($result->isError());
        $this->assertSame([], $this->rendered);
    }

    /**
     * Render check for menu 4 in $menuStoreIds, for a caller restricted to $allowedStoreIds (null = unrestricted).
     *
     * @param array|null $allowedStoreIds
     * @param array $menuStoreIds
     * @param int|null $fallback Store view the menu service resolves without one.
     * @return MenuRenderCheck
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<int> $menuStoreIds
     */
    private function tool(?array $allowedStoreIds, array $menuStoreIds, ?int $fallback = null): MenuRenderCheck
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturn(['id' => 4, 'identifier' => 'main', 'store_ids' => $menuStoreIds]);
        $menus->method('resolveImportStoreId')->willReturnCallback(
            static function (?int $storeId) use ($allowedStoreIds, $fallback): int {
                if ($storeId !== null && $allowedStoreIds !== null && !in_array($storeId, $allowedStoreIds, true)) {
                    throw new AuthorizationException(__('Outside the role.'));
                }
                return $storeId ?? $fallback ?? 1;
            }
        );
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('render')->willReturnCallback(function (string $identifier, int $storeId): array {
            $this->rendered[] = $storeId;
            return ['html' => '<a href="/">Home</a>', 'message' => null, 'error' => null];
        });
        return new MenuRenderCheck($features, $menus, $gateway, $this->storeScope($allowedStoreIds));
    }
}
