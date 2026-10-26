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
use PHPUnit\Framework\TestCase;

/**
 * The read-only preview needs a menu the caller may see, not one it may change.
 */
class CategorySyncPreviewTest extends TestCase
{
    use ToolPayloadTrait;

    public function testThePreviewLocatesTheMenuForReading(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->expects($this->once())->method('locate')->willReturn(['id' => 3, 'store_ids' => [1]]);
        $menus->expects($this->never())->method('locateForWrite');
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
        $content = $this->createMock(ContentService::class);
        $content->method('getWorkingDocument')->willReturn([]);
        $content->expects($this->never())->method('saveDraft');
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('getRootNodes')->willReturn([['uid' => 'r1', 'component' => 'desktop_menu_columns']]);

        $result = (new CategorySyncPreview(
            $features,
            $menus,
            $content,
            $tree,
            $this->createMock(MenuItemBuilder::class)
        ))->execute(['id' => 3, 'root_category_id' => 2]);

        $this->assertFalse($result->isError());
        $this->assertTrue($this->at($result, 'dry_run'));
    }
}
