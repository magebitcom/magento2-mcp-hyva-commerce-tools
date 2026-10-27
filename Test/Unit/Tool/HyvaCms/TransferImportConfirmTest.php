<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Hyva\CmsImportExport\Model\Import\ContentProcessor;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Transfer\TransferImport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * hyva_cms.import over a draft with unpublished changes overwrites it only after confirm (AC15).
 */
class TransferImportConfirmTest extends TestCase
{
    private const ARGUMENTS = [
        'entity_type' => 'cms_page',
        'id' => 7,
        'payload' => ['draft_content' => ['content' => []]],
    ];

    /**
     * @var ContentService&MockObject
     */
    private ContentService $contentService;

    protected function setUp(): void
    {
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('validate')
            ->willReturn(['valid' => true, 'schema_error' => null, 'issues' => []]);
        $this->contentService->method('getDocuments')->willReturn([
            'draft' => ['content' => [['uid' => 'a']]],
            'published' => ['content' => []],
        ]);
    }

    public function testOverwritingUnpublishedChangesWithoutConfirmWritesNothing(): void
    {
        $this->contentService->expects($this->never())->method('saveDraft');

        $payload = $this->payload($this->tool()->execute(self::ARGUMENTS));

        $this->assertTrue($payload['confirmation_required'] ?? null);
    }

    public function testConfirmOverwritesTheDraft(): void
    {
        $this->contentService->expects($this->once())->method('saveDraft')
            ->with('cms_page', 7, $this->isType('array'), 'Imported (MCP)')
            ->willReturn([]);

        $payload = $this->payload($this->tool()->execute(self::ARGUMENTS + ['confirm' => true]));

        $this->assertTrue($payload['saved'] ?? null);
        $this->assertFalse($payload['published'] ?? null);
    }

    /**
     * Import tool for an unrestricted caller; the target draft differs from the published content.
     *
     * @return TransferImport
     */
    private function tool(): TransferImport
    {
        $processor = $this->createMock(ContentProcessor::class);
        $processor->method('processForImport')->willReturnArgument(0);
        $processor->method('getWarnings')->willReturn([]);
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('normalizeDocument')->willReturnArgument(0);
        $tree->method('countComponents')->willReturn([]);
        $images = $this->createMock(ImageReferenceExtractor::class);
        $images->method('fromDocument')->willReturn([]);
        $index = $this->createMock(ContentIndex::class);
        $index->method('sameContent')->willReturn(false);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn([
            'type' => 'cms_page', 'id' => 7, 'identifier' => 'sale', 'title' => 'Sale', 'is_active' => true,
            'store_ids' => [0],
        ]);
        return new TransferImport(
            $locator,
            $this->contentService,
            $index,
            $tree,
            $processor,
            $this->createMock(EntityWriter::class),
            $images,
            $this->createMock(MediaFileInfo::class),
            $this->createMock(ContentAcl::class),
            $this->createMock(StoreScopeGuard::class)
        );
    }

    /**
     * Decoded JSON payload of a tool result.
     *
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
