<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\MenuBuilder;

use Hyva\MenuBuilder\Api\Data\MenuInterface;
use Hyva\MenuBuilder\Api\MenuRepositoryInterface;
use Hyva\MenuBuilder\Model\ResourceModel\Menu\Collection;
use Hyva\MenuBuilder\Model\ResourceModel\Menu\CollectionFactory;
use Magebit\McpHyvaCommerceTools\Model\Adapter\MenuBuilder\MenuGateway;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;

/**
 * T-164: identifier + store_id finds a menu whether it is active and published or not.
 */
class MenuGatewayFindIdTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * Menus by id with their store views: 3 = "main" in store view 1 (active, published), 4 = "main" in store view 2
     * (inactive, never published), 7 = "footer" in all store views (inactive), 8 = "footer" in store view 2.
     */
    private const MENUS = [
        3 => ['identifier' => 'main', 'store_ids' => [1]],
        4 => ['identifier' => 'main', 'store_ids' => [2]],
        7 => ['identifier' => 'footer', 'store_ids' => [0]],
        8 => ['identifier' => 'footer', 'store_ids' => [2]],
    ];

    /**
     * Store view filters each collection saw, in call order; null = no store filter.
     *
     * @var list<int|null>
     */
    private array $storeFilters = [];

    public function testAnInactiveUnpublishedMenuIsFoundInItsOwnStoreView(): void
    {
        $this->assertSame(4, $this->gateway()->findIdByIdentifier('main', 2));
        $this->assertSame(3, $this->gateway()->findIdByIdentifier('main', 1));
    }

    public function testTheStoreViewsOwnMenuWinsOverOneForAllStoreViews(): void
    {
        $this->assertSame(8, $this->gateway()->findIdByIdentifier('footer', 2));
    }

    public function testAMenuForAllStoreViewsIsTheFallback(): void
    {
        $this->assertSame(7, $this->gateway()->findIdByIdentifier('footer', 1));
        $this->assertSame([1, 0], $this->storeFilters);
    }

    /**
     * Store view 0 used to skip the store join and match a menu of any store view.
     *
     * @return void
     */
    public function testStoreViewZeroOnlyMatchesAMenuForAllStoreViews(): void
    {
        $this->assertNull($this->gateway()->findIdByIdentifier('main', 0));
        $this->assertSame(7, $this->gateway()->findIdByIdentifier('footer', 0));
    }

    public function testAMenuNotInTheStoreViewIsNotFound(): void
    {
        $this->assertNull($this->gateway()->findIdByIdentifier('main', 5));
    }

    public function testWithoutStoreIdTheLowestIdIsReturned(): void
    {
        $this->assertSame(3, $this->gateway()->findIdByIdentifier('main'));
        $this->assertSame([null], $this->storeFilters);
    }

    /**
     * Gateway over MENUS; the repository's storefront loader must not be used.
     *
     * @return MenuGateway
     */
    private function gateway(): MenuGateway
    {
        $repository = $this->createMock(MenuRepositoryInterface::class);
        $repository->expects($this->never())->method('getByIdentifier');
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Collection => $this->collection());

        return new MenuGateway(
            $this->optionalServices([
                CollectionFactory::class => $factory,
                MenuRepositoryInterface::class => $repository,
            ]),
            $this->createMock(Emulation::class)
        );
    }

    /**
     * Collection that filters MENUS by identifier and exact store view and returns the lowest id first.
     *
     * @return Collection
     */
    private function collection(): Collection
    {
        $filter = ['identifier' => null, 'store' => null];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, string $value) use (&$filter, $collection): Collection {
                $this->assertSame(MenuInterface::IDENTIFIER, $field);
                $filter['identifier'] = $value;
                return $collection;
            }
        );
        $collection->method('addStoreFilter')->willReturnCallback(
            function (int $store, bool $withAdmin) use (&$filter, $collection): Collection {
                $this->assertFalse($withAdmin);
                $filter['store'] = $store;
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturnCallback(function () use (&$filter): MenuInterface {
            $this->storeFilters[] = $filter['store'];
            $menu = $this->createMock(MenuInterface::class);
            foreach (self::MENUS as $id => $row) {
                if ($row['identifier'] === $filter['identifier']
                    && ($filter['store'] === null || in_array($filter['store'], $row['store_ids'], true))
                ) {
                    $menu->method('getId')->willReturn((string) $id);
                    return $menu;
                }
            }
            $menu->method('getId')->willReturn(null);
            return $menu;
        });
        return $collection;
    }
}
