<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Menu;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Api\LinkTargetResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuValidator;
use PHPUnit\Framework\TestCase;

class MenuValidatorTest extends TestCase
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
     * @var MenuValidator
     */
    private MenuValidator $validator;

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
            'desktop_menu_columns' => [
                'children' => [
                    'config' => [
                        'accepts' => [
                            'hyva_menu_item_level_0',
                            'hyva_menu_category_tree',
                            'hyva_menu_content_container',
                        ]
                    ]
                ]
            ],
            'mobile_menu' => [
                'children' => ['config' => ['accepts' => ['hyva_menu_item']]],
                'content' => ['max_level' => ['type' => 'range', 'default_value' => 2]],
            ],
            'menu_footer_columns' => ['children' => ['config' => ['accepts' => ['menu_footer_columns_item']]]],
            'menu_footer_columns_item' => [
                'require_parent' => true,
                'children' => ['config' => ['accepts' => ['hyva_menu_item']]],
                'content' => ['label' => ['type' => 'text']],
            ],
            'hyva_menu_item_level_0' => $item,
            'hyva_menu_item' => $item,
            'hyva_menu_category_tree' => [
                'require_parent' => true,
                'content' => ['category_ids' => ['type' => 'category_selector']],
            ],
            'hyva_menu_content_container' => ['children' => true, 'content' => ['label' => ['type' => 'text']]],
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $this->tree = new ComponentTree($provider);
        $this->builder = new MenuItemBuilder($this->tree, new DraftEditor($this->tree));

        $resolver = $this->createMock(LinkTargetResolverInterface::class);
        $resolver->method('getCategories')->willReturnCallback(static function (array $ids): array {
            $known = [
                10 => [
                    'exists' => true,
                    'active' => true,
                    'include_in_menu' => true,
                    'name' => 'Women',
                    'has_url_rewrite' => true,
                ],
                11 => [
                    'exists' => true,
                    'active' => false,
                    'include_in_menu' => true,
                    'name' => 'Old',
                    'has_url_rewrite' => true,
                ],
            ];
            $result = [];
            foreach ($ids as $id) {
                $result[$id] = $known[$id] ?? [
                    'exists' => false,
                    'active' => false,
                    'include_in_menu' => false,
                    'name' => '',
                    'has_url_rewrite' => false,
                ];
            }
            return $result;
        });
        $resolver->method('getProducts')->willReturn(
            ['OFF-1' => ['exists' => true, 'enabled' => false, 'name' => 'Off']]
        );
        $resolver->method('getCmsPages')->willReturn(
            ['about' => ['exists' => true, 'active' => true, 'store_ids' => [2]]]
        );
        $this->validator = new MenuValidator(
            $resolver,
            $this->tree,
            new ImageReferenceExtractor($this->tree, $this->createMock(ImageAssetPathResolverInterface::class)),
            $this->builder
        );
    }

    public function testDetectsLinkLabelDepthAndStructureProblems(): void
    {
        $document = $this->tree->newDocument('menu', 1);
        $desktop = $this->tree->createNode('desktop_menu_columns');
        $desktop['children'] = [
            $this->builder->build('desktop_menu_columns', ['type' => 'category', 'value' => 10, 'label' => 'Women']),
            $this->builder->build('desktop_menu_columns', ['type' => 'category', 'value' => 11, 'label' => 'Old']),
            $this->builder->build('desktop_menu_columns', ['type' => 'category', 'value' => 99, 'label' => 'Gone']),
            $this->builder->build(
                'desktop_menu_columns',
                ['type' => 'product', 'value' => 'OFF-1', 'label' => 'Promo']
            ),
            $this->builder->build(
                'desktop_menu_columns',
                ['type' => 'cms_page', 'value' => 'about', 'label' => 'About']
            ),
            $this->builder->build(
                'desktop_menu_columns',
                ['type' => 'custom_url', 'value' => 'https://bad url', 'label' => 'Women']
            ),
            $this->builder->build('desktop_menu_columns', ['type' => 'text', 'label' => '']),
            $this->builder->build('desktop_menu_columns', ['type' => 'content', 'label' => 'Promo block']),
        ];
        $mobile = $this->tree->createNode('mobile_menu');
        $deep = ['type' => 'category', 'value' => 10, 'label' => 'L1', 'children' => [
            ['type' => 'category', 'value' => 10, 'label' => 'L2', 'children' => [
                ['type' => 'category', 'value' => 10, 'label' => 'L3'],
            ]],
        ]];
        $mobile['children'] = [$this->builder->build('mobile_menu', $deep)];
        $document = $this->tree->insertNode($this->tree->insertNode($document, $desktop), $mobile);

        $issues = $this->validator->validate($document, ['store_ids' => [1], 'is_active' => true], 1);
        $codes = array_count_values(array_column($issues, 'code'));

        $this->assertArrayHasKey('inactive_category', $codes);
        $this->assertArrayHasKey('broken_category', $codes);
        $this->assertArrayHasKey('disabled_product', $codes);
        $this->assertArrayHasKey('store_view_mismatch', $codes);
        $this->assertArrayHasKey('broken_url', $codes);
        $this->assertArrayHasKey('empty_label', $codes);
        $this->assertArrayHasKey('empty_column', $codes);
        $this->assertArrayHasKey('empty_content_block', $codes);
        $this->assertArrayHasKey('duplicate_label', $codes);
        $this->assertArrayHasKey('too_deep_mobile', $codes);
        $this->assertSame('error', $issues[0]['severity'], 'Errors are listed first.');
    }

    public function testMenuLevelProblems(): void
    {
        $issues = $this->validator->validate(
            $this->tree->newDocument('menu', 1),
            ['store_ids' => [], 'is_active' => false, 'is_published' => false, 'assigned' => true]
        );
        $codes = array_column($issues, 'code');
        $this->assertContains('empty_menu', $codes);
        $this->assertContains('unassigned_menu', $codes);
        $this->assertContains('assigned_but_empty', $codes);
    }

    public function testCleanMenuHasNoIssues(): void
    {
        $desktop = $this->tree->createNode('desktop_menu_columns');
        $desktop['children'] = [
            $this->builder->build('desktop_menu_columns', ['type' => 'category', 'value' => 10, 'label' => 'Women'])
        ];
        $document = $this->tree->insertNode($this->tree->newDocument('menu', 1), $desktop);
        $this->assertSame(
            [],
            $this->validator->validate(
                $document,
                ['store_ids' => [0], 'is_active' => true, 'is_published' => true, 'assigned' => true]
            )
        );
    }
}
