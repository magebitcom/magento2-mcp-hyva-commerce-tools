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
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class MenuServiceTest extends TestCase
{
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
        $this->service = new MenuService(
            $this->createMock(MenuGatewayInterface::class),
            $this->createMock(ContentLocator::class),
            $this->createMock(ContentService::class),
            $this->tree,
            $this->createMock(MenuValidator::class),
            $this->builder,
            $resolver,
            $this->createMock(UsageFinder::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(DirectoryList::class),
            $this->createMock(FileDriver::class),
            $this->createMock(LoggerInterface::class)
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
