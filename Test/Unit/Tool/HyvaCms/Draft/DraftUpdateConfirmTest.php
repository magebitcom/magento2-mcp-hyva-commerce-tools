<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms\Draft;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Draft\DraftUpdate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DraftUpdateConfirmTest extends TestCase
{
    use ToolPayloadTrait;

    private const DROPPED = ['uid' => 'p1', 'component' => 'menu_item', 'children' => [['uid' => 'c1']]];
    private const OPERATIONS = [['op' => 'remove', 'uid' => 'p1']];

    /**
     * @var ContentService&MockObject
     */
    private ContentService&MockObject $contentService;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('getWorkingDocument')->willReturn([]);
    }

    public function testRemovingAMenuItemWithChildrenWithoutConfirmSavesNothing(): void
    {
        $this->contentService->expects($this->never())->method('saveDraft');

        $result = $this->tool('menu')->execute(['entity_type' => 'menu', 'id' => 3, 'operations' => self::OPERATIONS]);

        $this->assertTrue($this->payloadAt($result, 'confirmation_required'));
        $this->assertSame('remove_menu_item_with_children', $this->payloadAt($result, 'action'));
        $this->assertSame('c1', $this->payloadAt($result, 'affected.0.children.0.uid'));
    }

    public function testConfirmSavesTheMenuDraft(): void
    {
        $this->contentService->expects($this->once())->method('saveDraft')->willReturn([]);

        $result = $this->tool('menu')->execute([
            'entity_type' => 'menu',
            'id' => 3,
            'operations' => self::OPERATIONS,
            'confirm' => true,
        ]);

        $this->assertFalse($result->isError());
    }

    public function testPageDraftRemovalsNeedNoConfirm(): void
    {
        $this->contentService->expects($this->once())->method('saveDraft')->willReturn([]);

        $result = $this->tool('cms_page')->execute([
            'entity_type' => 'cms_page',
            'id' => 3,
            'operations' => self::OPERATIONS,
        ]);

        $this->assertFalse($result->isError());
    }

    /**
     * Draft update over an entity of the given type whose operations drop one subtree.
     *
     * @param string $type
     * @return DraftUpdate
     */
    private function tool(string $type): DraftUpdate
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn(['type' => $type, 'id' => 3]);
        $editor = $this->createMock(DraftEditor::class);
        $editor->method('apply')->willReturn(['document' => [], 'applied' => [], 'dropped' => [self::DROPPED]]);
        $builder = $this->createMock(MenuItemBuilder::class);
        $builder->method('describe')->willReturnArgument(0);
        return new DraftUpdate(
            $locator,
            $this->contentService,
            $this->createMock(ComponentTree::class),
            $editor,
            $this->createMock(ContentAcl::class),
            $builder
        );
    }
}
