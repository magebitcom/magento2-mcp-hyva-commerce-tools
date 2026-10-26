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

    public function testAnExplicitStoreViewIsCheckedAgainstTheRole(): void
    {
        $menus = $this->menus();
        $menus->expects($this->once())->method('assertStoreViewReadable')->with(2);

        $this->assertFalse($this->runTool(['store_id' => 2], $menus)->isError());
    }

    public function testAStoreViewOutsideTheRoleIsRefusedBeforeAnythingIsRead(): void
    {
        $menus = $this->menus();
        $menus->method('assertStoreViewReadable')->willThrowException(new AuthorizationException(__('Outside.')));
        $menus->expects($this->never())->method('planCategoryImport');

        $result = $this->runTool(['store_id' => 2], $menus);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testWithoutAStoreViewTheMenusOwnIsUsedWithoutTheCheck(): void
    {
        $menus = $this->menus();
        $menus->expects($this->never())->method('assertStoreViewReadable');

        $this->assertFalse($this->runTool([], $menus)->isError());
    }

    /**
     * Menu service for menu 3 with an empty plan.
     *
     * @return MenuService&MockObject
     */
    private function menus(): MenuService&MockObject
    {
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturn(['id' => 3, 'store_ids' => [1]]);
        $menus->method('lookupStoreId')->willReturn(1);
        $menus->method('planCategoryImport')->willReturn([
            'tree' => [],
            'create' => [],
            'skip_existing' => [],
            'inactive' => [],
            'hidden' => [],
            'missing_url_rewrites' => [],
            'duplicate_labels' => [],
        ]);
        return $menus;
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
