<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu\Category;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Category\CategorySyncPreview;
use Magento\Framework\Exception\AuthorizationException;
use Magebit\Mcp\Api\ToolResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The read-only preview needs a menu the caller may see, not one it may change.
 */
class CategorySyncPreviewTest extends TestCase
{
    use ToolPayloadTrait;

    public function testThePreviewLocatesTheMenuForReading(): void
    {
        $menus = $this->menus();
        $menus->expects($this->once())->method('locate');
        $menus->expects($this->never())->method('locateForWrite');

        $result = $this->runTool([], $menus);

        $this->assertFalse($result->isError());
        $this->assertTrue($this->at($result, 'dry_run'));
    }

    public function testTheStoreViewIsResolvedAgainstTheRoleWithTheMenusOwnViews(): void
    {
        $menus = $this->menus([1, 3], false);
        $menus->expects($this->once())->method('resolveImportStoreId')->with(null, [1, 3])->willReturn(3);
        $menus->expects($this->once())->method('planCategoryImport')->with(
            $this->anything(),
            2,
            2,
            3,
            false,
            false
        )->willReturn($this->emptyPlan());

        $this->assertFalse($this->runTool([], $menus)->isError());
    }

    public function testAnExplicitStoreViewIsHandedToTheRoleCheck(): void
    {
        $menus = $this->menus([1], false);
        $menus->expects($this->once())->method('resolveImportStoreId')->with(2, [1])->willReturn(2);

        $this->assertFalse($this->runTool(['store_id' => 2], $menus)->isError());
    }

    public function testAStoreViewRefusedByTheRoleIsRefusedBeforeAnythingIsRead(): void
    {
        $menus = $this->menus([1], false);
        $menus->method('resolveImportStoreId')->willThrowException(new AuthorizationException(__('Outside.')));
        $menus->expects($this->never())->method('planCategoryImport');

        $result = $this->runTool(['store_id' => 0], $menus);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    /**
     * Menu service for menu 3 with an empty plan.
     *
     * @param array $storeIds Store views of the menu.
     * @param bool $resolvesToOne Whether the store view resolution answers 1 by default.
     * @return MenuService&MockObject
     * @phpstan-param list<int> $storeIds
     */
    private function menus(array $storeIds = [1], bool $resolvesToOne = true): MenuService&MockObject
    {
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturn(['id' => 3, 'store_ids' => $storeIds]);
        if ($resolvesToOne) {
            $menus->method('resolveImportStoreId')->willReturn(1);
        }
        $menus->method('planCategoryImport')->willReturn($this->emptyPlan());
        return $menus;
    }

    /**
     * Import plan without any category.
     *
     * @return array
     * @phpstan-return array<string, list<mixed>>
     */
    private function emptyPlan(): array
    {
        return [
            'tree' => [],
            'create' => [],
            'skip_existing' => [],
            'inactive' => [],
            'hidden' => [],
            'missing_url_rewrites' => [],
            'duplicate_labels' => [],
        ];
    }

    /**
     * Run the preview tool.
     *
     * @param array $arguments Extra arguments next to the menu and root category.
     * @param MenuService $menus
     * @return ToolResultInterface
     * @phpstan-param array<string, mixed> $arguments
     */
    private function runTool(array $arguments, MenuService $menus): ToolResultInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $content = $this->createMock(ContentService::class);
        $content->method('getWorkingDocument')->willReturn([]);
        $content->expects($this->never())->method('saveDraft');
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('getRootNodes')->willReturn([['uid' => 'r1', 'component' => 'desktop_menu_columns']]);

        return (new CategorySyncPreview(
            $features,
            $menus,
            $content,
            $tree,
            $this->createMock(MenuItemBuilder::class)
        ))->execute(['id' => 3, 'root_category_id' => 2] + $arguments);
    }
}
