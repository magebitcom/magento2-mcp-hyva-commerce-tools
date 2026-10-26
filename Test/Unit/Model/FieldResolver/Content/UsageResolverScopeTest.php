<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content\BlockUsageResolver;
use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu\MenuUsageResolver;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use PHPUnit\Framework\TestCase;

/**
 * The `usage` field of cms_block.get and hyva_menu.get leaves out content of other websites' store views (finding:
 * unscoped image/audit lists).
 */
class UsageResolverScopeTest extends TestCase
{
    use ContentScopeStub;

    private const STORE_IDS = ['cms_page' => [1 => [1], 2 => [2]], 'cms_block' => [7 => [2]]];

    public function testBlockUsageListsOnlyTheRolesContent(): void
    {
        $finder = $this->createMock(UsageFinder::class);
        $finder->method('findBlockUsage')->willReturn([
            'hyva_content' => [['entity_type' => 'cms_page', 'id' => 1], ['entity_type' => 'cms_block', 'id' => 7]],
            'magento_content' => [['entity_type' => 'cms_page', 'id' => 2]],
            'widget_instances' => [['instance_id' => 1, 'store_ids' => '1'], ['instance_id' => 2, 'store_ids' => '2']],
            'total' => 5,
        ]);

        $usage = (new BlockUsageResolver($finder, $this->contentScope([1], self::STORE_IDS)))
            ->resolve($this->entity('cms_block'), []);

        $this->assertIsArray($usage);
        $this->assertSame([1], array_column($usage['hyva_content'], 'id'));
        $this->assertSame([], $usage['magento_content']);
        $this->assertSame([1], array_column($usage['widget_instances'], 'instance_id'));
        $this->assertSame(2, $usage['total']);
        $this->assertSame(3, $usage['usages_hidden_by_permissions']);

        $all = (new BlockUsageResolver($finder, $this->contentScope(null)))->resolve($this->entity('cms_block'), []);
        $this->assertIsArray($all);
        $this->assertSame(5, $all['total']);
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $all);
    }

    public function testMenuUsageListsOnlyTheRolesContent(): void
    {
        $menus = $this->createMock(MenuService::class);
        $menus->method('findUsage')->willReturn([
            'identifier' => 'main',
            'used' => true,
            'total' => 2,
            'design_config' => [],
            'hyva_cms_content' => [],
            'magento_cms_content' => [['entity_type' => 'cms_page', 'id' => 2]],
            'widget_instances' => [['instance_id' => 2, 'store_ids' => '1']],
            'layout_and_templates' => [],
        ]);

        $usage = (new MenuUsageResolver($menus, $this->contentScope([1], self::STORE_IDS)))
            ->resolve($this->entity('menu'), []);

        $this->assertIsArray($usage);
        $this->assertSame([], $usage['magento_cms_content']);
        $this->assertSame(1, $usage['total']);
        $this->assertTrue($usage['used']);
        $this->assertSame(1, $usage['usages_hidden_by_permissions']);
    }

    /**
     * Located entity.
     *
     * @param string $type
     * @return array
     * @phpstan-return array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>}
     */
    private function entity(string $type): array
    {
        return ['type' => $type, 'id' => 7, 'identifier' => 'main', 'title' => '', 'is_active' => true,
            'store_ids' => [1]];
    }
}
