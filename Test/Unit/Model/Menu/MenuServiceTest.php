<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Menu;

use Magebit\Mcp\Api\LoggerInterface;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\LinkTargetResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuValidator;
use Magebit\McpHyvaCommerceTools\Model\Security\CatalogScopeReader;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class MenuServiceTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @var ComponentTree
     */
    private ComponentTree $tree;

    /**
     * @var MenuItemBuilder
     */
    private MenuItemBuilder $builder;

    /**
     * @var MenuService
     */
    private MenuService $service;

    /**
     * @var LinkTargetResolverInterface
     */
    private LinkTargetResolverInterface $resolver;

    protected function setUp(): void
    {
        $item = ['children' => [
            'config' => ['accepts' => ['hyva_menu_item', 'hyva_menu_category_tree']]
        ], 'require_parent' => true,
            'content' => [
                'text_only' => ['type' => 'boolean', 'default_value' => false],
                'label' => ['type' => 'text'],
                'link' => ['type' => 'link'],
            ]];
        $components = [
            'desktop_menu_columns' => ['children' => ['config' => ['accepts' => ['hyva_menu_item_level_0']]]],
            'mobile_menu' => ['children' => ['config' => ['accepts' => ['hyva_menu_item']]]],
            'hyva_menu_item_level_0' => $item,
            'hyva_menu_item' => $item,
            'menu_footer_columns' => ['children' => ['config' => ['accepts' => ['menu_footer_columns_item']]]],
            'menu_footer_columns_item' => [
                'require_parent' => true,
                'children' => ['config' => ['accepts' => ['hyva_menu_item']]],
                'content' => ['label' => ['type' => 'text']],
            ],
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $this->tree = new ComponentTree($provider);
        $this->builder = new MenuItemBuilder($this->tree, new DraftEditor($this->tree));

        $resolver = $this->createMock(LinkTargetResolverInterface::class);
        $resolver->method('getCategoryTree')->willReturn([
            [
                'id' => 20,
                'name' => 'Women',
                'active' => true,
                'include_in_menu' => true,
                'has_url_rewrite' => true,
                'level' => 1,
                'children' => [
                    [
                        'id' => 21,
                        'name' => 'Tops',
                        'active' => true,
                        'include_in_menu' => true,
                        'has_url_rewrite' => true,
                        'level' => 2,
                        'children' => [],
                    ],
                    [
                        'id' => 22,
                        'name' => 'Old',
                        'active' => false,
                        'include_in_menu' => true,
                        'has_url_rewrite' => true,
                        'level' => 2,
                        'children' => [],
                    ],
                    [
                        'id' => 23,
                        'name' => 'Hidden',
                        'active' => true,
                        'include_in_menu' => false,
                        'has_url_rewrite' => false,
                        'level' => 2,
                        'children' => [],
                    ],
                ],
            ],
            [
                'id' => 30,
                'name' => 'Sale',
                'active' => true,
                'include_in_menu' => true,
                'has_url_rewrite' => false,
                'level' => 1,
                'children' => [],
            ],
        ]);
        $this->resolver = $resolver;
        $this->service = $this->service(null);
    }

    public function testARestrictedRoleSeesOnlyTheDesignConfigScopesItMayOpen(): void
    {
        $rows = [];
        foreach ([['default', 0], ['websites', 1], ['websites', 2], ['websites', 9], ['stores', 1], ['stores', 2]]
                 as [$scope, $scopeId]) {
            $rows[] = ['location' => 'header', 'path' => 'design/header/topmenu_identifier', 'scope' => $scope,
                'scope_id' => $scopeId];
        }

        $restricted = $this->service([1], [], [1 => [1], 2 => [2]])->filterAssignments($rows);
        $this->assertSame(
            [['websites', 1], ['stores', 1]],
            array_map(static fn (array $r): array => [$r['scope'], $r['scope_id']], $restricted['visible'])
        );
        $this->assertSame(4, $restricted['hidden']);
        $this->assertSame(['visible' => $rows, 'hidden' => 0], $this->service->filterAssignments($rows));

        $report = $this->service([1], [], [1 => [1]])->withScopedAssignments(
            ['used' => true, 'total' => 9, 'design_config' => $rows, 'usages_hidden_by_permissions' => 1]
        );
        $this->assertTrue($report['used']);
        $this->assertIsArray($report['design_config']);
        $this->assertCount(2, $report['design_config']);
        $this->assertSame(5, $report['total']);
        $this->assertSame(5, $report['usages_hidden_by_permissions']);
    }

    public function testCategoryImportRefusesARootOutsideTheRole(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service([1], [2 => '1/12/2'])
            ->planCategoryImport($this->tree->newDocument('menu', 1), 2, 2, 1, false, false);
    }

    public function testCategoryImportUnderTheTreeRootKeepsOnlyTheHeldStoreRoots(): void
    {
        $resolver = $this->createMock(LinkTargetResolverInterface::class);
        $resolver->method('getCategoryTree')->willReturn([
            ['id' => 11, 'name' => 'Main', 'active' => true, 'include_in_menu' => true, 'has_url_rewrite' => true,
                'level' => 1, 'children' => []],
            ['id' => 12, 'name' => 'Other', 'active' => true, 'include_in_menu' => true, 'has_url_rewrite' => true,
                'level' => 1, 'children' => []],
        ]);
        $this->resolver = $resolver;

        $plan = $this->service([1], [1 => '1'])
            ->planCategoryImport($this->tree->newDocument('menu', 1), 1, 2, 1, false, false);

        $this->assertSame([11], array_column($plan['tree'], 'id'));
    }

    public function testImportStoreOfARestrictedCallerIsTheMenusFirstViewItMayRead(): void
    {
        $this->assertSame(3, $this->service([3])->resolveImportStoreId(null, [1, 3]));
    }

    public function testImportStoreOfARestrictedCallerFallsBackToItsFirstViewForAMenuOnStoreZero(): void
    {
        $this->assertSame(2, $this->service([2])->resolveImportStoreId(null, [0]));
        $this->assertSame(2, $this->service([2])->resolveImportStoreId(null, []));
    }

    public function testImportStoreOfARestrictedCallerWithoutAnyViewIsRefused(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service([])->resolveImportStoreId(null, [1]);
    }

    public function testImportStoreZeroIsRefusedForARestrictedCaller(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service([2])->resolveImportStoreId(0, [2]);
    }

    public function testImportStoreOutsideTheRoleIsRefused(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service([2])->resolveImportStoreId(1, [2]);
    }

    public function testImportStoreGivenWithinTheRoleIsKept(): void
    {
        $this->assertSame(2, $this->service([1, 2])->resolveImportStoreId(2, [1]));
    }

    public function testImportStoreOfAnUnrestrictedCallerIsTheMenusFirstStoreView(): void
    {
        $this->assertSame(1, $this->service(null)->resolveImportStoreId(null, [0, 1, 3]));
        $this->assertSame(3, $this->service(null)->resolveImportStoreId(3, [1]));
    }

    /**
     * Menu service for a caller restricted to the store views, or unrestricted (null).
     *
     * @param array|null $allowedStoreIds
     * @param array $categoryPaths Tree path per category id.
     * @param array $websiteStores Store view ids per website id.
     * @return MenuService
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param array<int, string> $categoryPaths
     * @phpstan-param array<int, list<int>> $websiteStores
     */
    private function service(?array $allowedStoreIds, array $categoryPaths = [], array $websiteStores = []): MenuService
    {
        $paths = $this->createMock(CatalogScopeReader::class);
        $paths->method('categoryPaths')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($categoryPaths, array_flip($ids))
        );
        $stores = [];
        foreach ($websiteStores as $websiteId => $storeIds) {
            foreach ($storeIds as $storeId) {
                $store = $this->createMock(StoreInterface::class);
                $store->method('getId')->willReturn($storeId);
                $store->method('getWebsiteId')->willReturn($websiteId);
                $stores[] = $store;
            }
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        return new MenuService(
            $this->createMock(MenuGatewayInterface::class),
            $this->createMock(ContentLocator::class),
            $this->createMock(ContentService::class),
            $this->tree,
            $this->createMock(MenuValidator::class),
            $this->builder,
            $this->resolver,
            $this->createMock(UsageFinder::class),
            $this->createMock(ResourceConnection::class),
            $storeManager,
            $this->createMock(DirectoryList::class),
            $this->createMock(FileDriver::class),
            $this->createMock(LoggerInterface::class),
            $this->storeScope($allowedStoreIds),
            $paths
        );
    }

    public function testPlanFiltersInactiveHiddenAndReportsExisting(): void
    {
        [$document] = $this->menuWithWomen();
        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);

        $this->assertSame([21, 30], array_column($plan['create'], 'id'));
        $this->assertSame([20], array_column($plan['skip_existing'], 'id'));
        $this->assertSame([22], array_column($plan['inactive'], 'id'));
        $this->assertSame([23], array_column($plan['hidden'], 'id'));
        $this->assertSame([30], array_column($plan['missing_url_rewrites'], 'id'));
    }

    public function testNestedImportPutsNewChildrenUnderTheExistingItem(): void
    {
        [$document, $rootUid, $womenUid] = $this->menuWithWomen();
        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        $placements = $this->service->placements(
            $document,
            $plan['tree'],
            [['uid' => $rootUid, 'component' => 'desktop_menu_columns']],
            'nested'
        );

        $byParent = [];
        foreach ($placements as $placement) {
            $byParent[$placement['parent_uid']][] = $placement['spec']['label'] ?? null;
        }
        $this->assertSame(['Tops'], $byParent[$womenUid] ?? null, 'Tops nests under the existing Women item.');
        $this->assertSame(['Sale'], $byParent[$rootUid] ?? null, 'Sale is a new top-level item.');
    }

    public function testNestedImportIgnoresExistingItemsOutsideTheTargetedRoots(): void
    {
        [$document, , ] = $this->menuWithWomen();
        $mobile = $this->tree->createNode('mobile_menu');
        $mobileUid = Cast::string($mobile['uid'] ?? '');
        $document = $this->tree->insertNode($document, $mobile);
        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        $placements = $this->service->placements(
            $document,
            $plan['tree'],
            [['uid' => $mobileUid, 'component' => 'mobile_menu']],
            'nested'
        );

        $this->assertSame([$mobileUid], array_values(array_unique(array_column($placements, 'parent_uid'))));
        $this->assertSame(['Sale'], array_map(static fn (array $p) => $p['spec']['label'] ?? null, $placements));
    }

    public function testNewChildrenOfItemsOutsideTheTargetedRootsAreReportedAsSkipped(): void
    {
        [$document, , ] = $this->menuWithWomen();
        $mobile = $this->tree->createNode('mobile_menu');
        $mobileUid = Cast::string($mobile['uid'] ?? '');
        $document = $this->tree->insertNode($document, $mobile);
        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        $skipped = [];
        $this->service->placements(
            $document,
            $plan['tree'],
            [['uid' => $mobileUid, 'component' => 'mobile_menu']],
            'nested',
            $skipped
        );

        $this->assertSame([['id' => 21, 'name' => 'Tops']], $skipped);
    }

    public function testFlatImportListsEveryNewCategoryAtTheTargetLevel(): void
    {
        [$document, $rootUid] = $this->menuWithWomen();
        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        $placements = $this->service->placements(
            $document,
            $plan['tree'],
            [['uid' => $rootUid, 'component' => 'desktop_menu_columns']],
            'flat'
        );

        $this->assertSame(
            ['Tops', 'Sale'],
            array_map(static fn (array $p) => $p['spec']['label'] ?? null, $placements)
        );
    }

    public function testFooterImportKeepsEveryCategoryLinkAndIsIdempotent(): void
    {
        $footer = $this->tree->createNode('menu_footer_columns');
        $footerUid = Cast::string($footer['uid'] ?? '');
        $document = $this->tree->insertNode($this->tree->newDocument('menu', 1), $footer);
        $parents = [['uid' => $footerUid, 'component' => 'menu_footer_columns']];

        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        foreach ($this->service->placements($document, $plan['tree'], $parents, 'nested') as $p) {
            $document = $this->tree->insertNode(
                $document,
                $this->builder->build($p['parent_component'], $p['spec']),
                $p['parent_uid']
            );
        }

        $linked = [];
        $this->tree->walk($document, function (array $node) use (&$linked): void {
            if (is_array($node['link'] ?? null)) {
                $linked[] = $node['link']['value'] ?? null;
            }
        });
        sort($linked);
        $this->assertSame([20, 21, 30], $linked, 'Women, Tops and Sale all keep their link.');

        $again = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        $this->assertSame([], $again['create']);
        $this->assertSame([], $this->service->placements($document, $again['tree'], $parents, 'nested'));
    }

    public function testReimportIsIdempotent(): void
    {
        [$document, $rootUid] = $this->menuWithWomen();
        $parents = [['uid' => $rootUid, 'component' => 'desktop_menu_columns']];
        $plan = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        foreach ($this->service->placements($document, $plan['tree'], $parents, 'nested') as $p) {
            $document = $this->tree->insertNode(
                $document,
                $this->builder->build($p['parent_component'], $p['spec']),
                $p['parent_uid']
            );
        }
        $again = $this->service->planCategoryImport($document, 2, 2, 1, false, false);
        $this->assertSame([], $again['create']);
        $this->assertSame([], $this->service->placements($document, $again['tree'], $parents, 'nested'));
    }

    /**
     * Menu with a desktop root holding an existing "Women" (20) item.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function menuWithWomen(): array
    {
        $root = $this->tree->createNode('desktop_menu_columns');
        $women = $this->builder->build(
            'desktop_menu_columns',
            ['type' => 'category', 'value' => 20, 'label' => 'Women']
        );
        $root['children'] = [$women];
        $document = $this->tree->insertNode($this->tree->newDocument('menu', 1), $root);
        return [$document, Cast::string($root['uid'] ?? ''), Cast::string($women['uid'] ?? '')];
    }
}
