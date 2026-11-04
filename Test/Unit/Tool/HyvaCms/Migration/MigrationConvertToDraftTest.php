<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms\Migration;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Migration\MigrationConvertToDraft;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * hyva_cms.migration.convert_to_draft: confirm only to replace a Hyvä draft; every reply says whether it wrote (T-152).
 */
class MigrationConvertToDraftTest extends TestCase
{
    private const DOCUMENT = ['content' => [['uid' => 'a', 'type' => 'html']]];

    /**
     * @var ContentService&MockObject
     */
    private ContentService $contentService;

    protected function setUp(): void
    {
        $this->contentService = $this->createMock(ContentService::class);
    }

    public function testWithoutAHyvaDraftTheFirstCallWritesAndSaysSo(): void
    {
        $this->contentService->method('getDocuments')->willReturn(['draft' => null, 'published' => null]);
        $this->contentService->expects($this->once())->method('saveDraft')
            ->willReturn(['version_id' => 25, 'version_uid' => 'v25', 'warnings' => []]);

        $payload = $this->payload($this->tool()->execute(['entity_type' => 'cms_page', 'identifier' => 'about']));

        $this->assertFalse($payload['dry_run'] ?? null);
        $this->assertTrue($payload['converted'] ?? null);
        $this->assertFalse($payload['published'] ?? null);
    }

    public function testAnExistingHyvaDraftNeedsConfirmAndWritesNothing(): void
    {
        $this->contentService->method('getDocuments')->willReturn(['draft' => self::DOCUMENT, 'published' => null]);
        $this->contentService->expects($this->never())->method('saveDraft');

        $payload = $this->payload($this->tool()->execute(['entity_type' => 'cms_page', 'identifier' => 'about']));

        $this->assertTrue($payload['dry_run'] ?? null);
        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertSame('replace_hyva_draft_with_migration', $payload['action'] ?? null);
    }

    public function testConfirmReplacesTheHyvaDraft(): void
    {
        $this->contentService->method('getDocuments')->willReturn(['draft' => self::DOCUMENT, 'published' => null]);
        $this->contentService->expects($this->once())->method('saveDraft')
            ->willReturn(['version_id' => 26, 'version_uid' => 'v26', 'warnings' => []]);

        $payload = $this->payload($this->tool()->execute([
            'entity_type' => 'cms_page',
            'identifier' => 'about',
            'confirm' => true,
        ]));

        $this->assertFalse($payload['dry_run'] ?? null);
        $this->assertTrue($payload['converted'] ?? null);
    }

    /**
     * Tool over page 12 whose Magento content converts into one HTML component.
     *
     * @return MigrationConvertToDraft
     */
    private function tool(): MigrationConvertToDraft
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn(['type' => 'cms_page', 'id' => 12]);
        $migration = $this->createMock(MigrationService::class);
        $migration->method('convert')->willReturn(['document' => self::DOCUMENT, 'warnings' => [], 'analysis' => []]);
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('getRootNodes')->willReturn(self::DOCUMENT['content']);
        $tree->method('countComponents')->willReturn(['html' => 1]);
        $preview = $this->createMock(PreviewService::class);
        $preview->method('getPreview')
            ->willReturn(['admin_editor_url' => '', 'stores' => [], 'error' => null, 'note' => '']);
        return new MigrationConvertToDraft(
            $locator,
            $migration,
            $this->contentService,
            $tree,
            $preview,
            $this->createMock(ContentAcl::class)
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
