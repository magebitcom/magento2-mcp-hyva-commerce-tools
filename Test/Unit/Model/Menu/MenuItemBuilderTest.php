<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Menu;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use PHPUnit\Framework\TestCase;

class MenuItemBuilderTest extends TestCase
{
    /**
     * @var MenuItemBuilder
     */
    private MenuItemBuilder $builder;

    protected function setUp(): void
    {
        $link = ['text_only' => ['type' => 'boolean'], 'label' => ['type' => 'text'], 'link' => ['type' => 'link']];
        $components = [
            'menu_footer_columns' => ['children' => ['config' => ['accepts' => ['menu_footer_columns_item']]]],
            'menu_footer_columns_item' => [
                'require_parent' => true,
                'children' => ['config' => ['accepts' => ['hyva_menu_item']]],
                'content' => ['label' => ['type' => 'text']],
            ],
            'hyva_menu_item' => [
                'require_parent' => true,
                'children' => ['config' => ['accepts' => ['hyva_menu_item']]],
                'content' => $link,
            ],
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $tree = new ComponentTree($provider);
        $this->builder = new MenuItemBuilder($tree, new DraftEditor($tree));
    }

    public function testFooterLinkIsWrappedInAColumnInsteadOfDropped(): void
    {
        $spec = ['type' => 'category', 'value' => 99, 'label' => 'Ghost category'];
        $this->assertTrue($this->builder->wrapsLinkInFooterColumn('menu_footer_columns', $spec));

        $column = $this->builder->build('menu_footer_columns', $spec);

        $this->assertSame('menu_footer_columns_item', $column['component']);
        $this->assertSame('Ghost category', $column['label']);
        $this->assertArrayNotHasKey('link', $column);
        $children = $this->children($column);
        $this->assertCount(1, $children);
        $child = $children[0];
        $this->assertSame('hyva_menu_item', $child['component']);
        $this->assertSame(
            ['type' => 'category', 'value' => 99],
            array_intersect_key($this->children(['children' => [$child['link'] ?? []]])[0], ['type' => 0, 'value' => 0])
        );
    }

    public function testFooterLinkKeepsItsChildrenAfterTheLink(): void
    {
        $column = $this->builder->build('menu_footer_columns', [
            'type' => 'category',
            'value' => 20,
            'label' => 'Women',
            'children' => [['type' => 'category', 'value' => 21, 'label' => 'Tops']],
        ]);

        $this->assertSame(
            ['Women', 'Tops'],
            array_map(fn (array $c): string => $this->builder->labelOf($c), $this->children($column))
        );
        $this->assertSame(
            [],
            $this->children($this->children($column)[0]),
            'The wrapped link carries no copy of the children.'
        );
    }

    public function testFooterTextColumnIsUnchanged(): void
    {
        $spec = ['type' => 'text', 'label' => 'Help'];
        $this->assertFalse($this->builder->wrapsLinkInFooterColumn('menu_footer_columns', $spec));
        $column = $this->builder->build('menu_footer_columns', $spec);
        $this->assertSame([], $this->children($column));
    }

    public function testLinkFieldsAreUnsupportedOnLabelOnlyComponents(): void
    {
        $arguments = ['id', 'uid', 'label', 'type', 'value', 'style'];
        $this->assertSame(
            ['type', 'value', 'style'],
            $this->builder->unsupportedFields('menu_footer_columns_item', $arguments)
        );
        $this->assertSame(
            ['text_only'],
            $this->builder->unsupportedFields('hyva_menu_content_container', ['text_only'])
        );
        $this->assertSame([], $this->builder->unsupportedFields('menu_footer_columns_item', ['id', 'uid', 'label']));
        $this->assertSame([], $this->builder->unsupportedFields('hyva_menu_item', $arguments));
        $this->assertSame([], $this->builder->unsupportedFields('hyva_menu_item_level_0', $arguments));
    }

    public function testLinksInsideAColumnAreNotWrapped(): void
    {
        $spec = ['type' => 'custom_url', 'value' => '/sale', 'label' => 'Sale'];
        $this->assertFalse($this->builder->wrapsLinkInFooterColumn('menu_footer_columns_item', $spec));
        $this->assertSame('hyva_menu_item', $this->builder->build('menu_footer_columns_item', $spec)['component']);
    }

    /**
     * Child nodes of a built node.
     *
     * @param array $node
     * @return array
     * @phpstan-param array<mixed> $node
     * @phpstan-return list<array<string, mixed>>
     */
    private function children(array $node): array
    {
        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $this->assertIsArray($child);
            $children[] = array_combine(array_map('strval', array_keys($child)), array_values($child));
        }
        return $children;
    }
}
