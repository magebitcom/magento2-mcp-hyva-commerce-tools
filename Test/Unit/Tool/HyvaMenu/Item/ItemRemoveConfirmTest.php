<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu\Item;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Item\ItemRemove;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ItemRemoveConfirmTest extends TestCase
{
    use ToolPayloadTrait;

    private const PARENT = ['uid' => 'parent', 'component' => 'menu_link',
        'children' => [['uid' => 'child', 'component' => 'menu_link', 'children' => []]]];
    private const LEAF = ['uid' => 'leaf', 'component' => 'menu_link', 'children' => []];
    private const SAVED = ['version_id' => 12, 'version_uid' => 'v-12', 'warnings' => []];

    public function testRemovingAnItemWithChildrenWithoutConfirmSavesNothing(): void
    {
        $contentService = $this->contentService();
        $contentService->expects($this->never())->method('saveDraft');
        $tree = $this->tree(self::PARENT);
        $tree->expects($this->never())->method('removeNode');

        $result = $this->tool($contentService, $tree)->execute(['id' => 3, 'uid' => 'parent']);

        $this->assertFalse($result->isError());
        $this->assertTrue($this->at($result, 'confirmation_required'));
        $this->assertSame('remove_menu_item_with_children', $this->at($result, 'action'));
        $this->assertSame('parent', $this->at($result, 'affected.0.uid'));
    }

    public function testConfirmRemovesTheItemWithChildren(): void
    {
        $contentService = $this->contentService();
        $contentService->expects($this->once())->method('saveDraft')->willReturn(self::SAVED);
        $tree = $this->tree(self::PARENT);
        $tree->expects($this->once())->method('removeNode')->with($this->anything(), 'parent')
            ->willReturn(['document' => ['root' => []], 'removed' => self::PARENT]);

        $result = $this->tool($contentService, $tree)->execute(['id' => 3, 'uid' => 'parent', 'confirm' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame('parent', $this->at($result, 'removed.uid'));
        $this->assertFalse($this->at($result, 'published'));
    }

    public function testRemovingAnItemWithoutChildrenNeedsNoConfirm(): void
    {
        $contentService = $this->contentService();
        $contentService->expects($this->once())->method('saveDraft')->willReturn(self::SAVED);
        $tree = $this->tree(self::LEAF);
        $tree->expects($this->once())->method('removeNode')->with($this->anything(), 'leaf')
            ->willReturn(['document' => ['root' => []], 'removed' => self::LEAF]);

        $result = $this->tool($contentService, $tree)->execute(['id' => 3, 'uid' => 'leaf']);

        $this->assertFalse($result->isError());
        $this->assertSame('leaf', $this->at($result, 'removed.uid'));
    }

    /**
     * Content service that returns a menu draft.
     *
     * @return ContentService&MockObject
     */
    private function contentService(): ContentService&MockObject
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['root' => []]);
        return $contentService;
    }

    /**
     * Component tree in which the requested item is the given node.
     *
     * @param array $node
     * @return ComponentTree&MockObject
     * @phpstan-param array<string, mixed> $node
     */
    private function tree(array $node): ComponentTree&MockObject
    {
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('findNode')->willReturn($node);
        return $tree;
    }

    /**
     * Item remove over menu 3.
     *
     * @param ContentService $contentService
     * @param ComponentTree $tree
     * @return ItemRemove
     */
    private function tool(ContentService $contentService, ComponentTree $tree): ItemRemove
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturn(['id' => 3, 'identifier' => 'main', 'title' => 'Main menu',
            'is_active' => true, 'store_ids' => [0]]);
        $builder = $this->createMock(MenuItemBuilder::class);
        $builder->method('describe')->willReturnCallback(
            static fn (array $node): array => ['uid' => $node['uid'] ?? null]
        );
        return new ItemRemove($features, $menus, $contentService, $tree, $builder);
    }
}
