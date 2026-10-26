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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Draft\DraftCreate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DraftCreateConfirmTest extends TestCase
{
    use ToolPayloadTrait;

    private const PAGE = ['type' => 'cms_page', 'id' => 7, 'identifier' => 'sale', 'title' => 'Sale',
        'is_active' => true, 'store_ids' => [1]];
    private const DRAFT = ['root' => ['draft-change']];
    private const PUBLISHED = ['root' => ['published']];
    private const SAVED = ['version_id' => 5, 'version_uid' => 'v-5', 'warnings' => []];

    /**
     * @var ContentService&MockObject
     */
    private ContentService&MockObject $contentService;

    /**
     * @var ContentIndex&MockObject
     */
    private ContentIndex&MockObject $contentIndex;

    /**
     * @var DraftCreate
     */
    private DraftCreate $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locate')->willReturn(self::PAGE);
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('getDocuments')
            ->willReturn(['draft' => self::DRAFT, 'published' => self::PUBLISHED]);
        $this->contentIndex = $this->createMock(ContentIndex::class);
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('countComponents')->willReturn(['row' => 1]);
        $this->tool = new DraftCreate(
            $locator,
            $this->contentService,
            $this->contentIndex,
            $tree,
            $this->createMock(DraftEditor::class),
            $this->createMock(ContentAcl::class)
        );
    }

    public function testReplacingUnpublishedChangesWithoutConfirmSavesNothing(): void
    {
        $this->contentIndex->method('sameContent')->willReturn(false);
        $this->contentService->expects($this->never())->method('saveDraft');

        $result = $this->tool->execute(['entity_type' => 'cms_page', 'id' => 7, 'source' => 'published']);

        $this->assertFalse($result->isError());
        $this->assertTrue($this->at($result, 'confirmation_required'));
        $this->assertSame('replace_draft', $this->at($result, 'action'));
        $this->assertSame(7, $this->at($result, 'affected.0.id'));
    }

    public function testConfirmReplacesTheDraft(): void
    {
        $this->contentIndex->method('sameContent')->willReturn(false);
        $this->contentService->expects($this->once())->method('saveDraft')
            ->with('cms_page', 7, self::PUBLISHED)->willReturn(self::SAVED);

        $result = $this->tool->execute([
            'entity_type' => 'cms_page',
            'id' => 7,
            'source' => 'published',
            'confirm' => true,
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('v-5', $this->at($result, 'draft.version_uid'));
        $this->assertFalse($this->at($result, 'published'));
    }

    public function testADraftWithoutUnpublishedChangesNeedsNoConfirm(): void
    {
        $this->contentIndex->method('sameContent')->willReturn(true);
        $this->contentService->expects($this->once())->method('saveDraft')->willReturn(self::SAVED);

        $result = $this->tool->execute(['entity_type' => 'cms_page', 'id' => 7, 'source' => 'published']);

        $this->assertFalse($result->isError());
        $this->assertSame('published', $this->at($result, 'source'));
    }
}
