<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Image;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageReplace;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * hyva_media.image.replace with scope=global rewrites drafts only after confirm (AC15).
 */
class ImageReplaceConfirmTest extends TestCase
{
    private const TARGETS = [
        ['entity_type' => 'cms_page', 'id' => 7, 'identifier' => 'sale'],
        ['entity_type' => 'cms_block', 'id' => 3, 'identifier' => 'promo'],
    ];

    /**
     * @var ImageContentEditor&MockObject
     */
    private ImageContentEditor $editor;

    protected function setUp(): void
    {
        $this->editor = $this->createMock(ImageContentEditor::class);
        $this->editor->method('normalize')->willReturnArgument(0);
        $this->editor->method('findTargets')->willReturn(['editable' => self::TARGETS, 'not_editable' => []]);
        $this->editor->method('replaceRewrite')->willReturn(static fn (array $node): array => $node);
    }

    public function testAGlobalReplaceWithoutConfirmRewritesNothing(): void
    {
        $this->editor->expects($this->never())->method('rewrite');

        $payload = $this->payload($this->tool()->execute([
            'old_path' => 'wysiwyg/old.jpg',
            'new_path' => 'wysiwyg/new.jpg',
            'scope' => 'global',
        ]));

        $this->assertTrue($payload['confirmation_required'] ?? null);
    }

    public function testConfirmRewritesEveryDraft(): void
    {
        $this->editor->expects($this->once())->method('rewrite')
            ->with(self::TARGETS, $this->isType('callable'), 'Image replaced (MCP)')
            ->willReturn([]);

        $payload = $this->payload($this->tool()->execute([
            'old_path' => 'wysiwyg/old.jpg',
            'new_path' => 'wysiwyg/new.jpg',
            'scope' => 'global',
            'confirm' => true,
        ]));

        $this->assertArrayNotHasKey('confirmation_required', $payload);
        $this->assertFalse($payload['published'] ?? null);
    }

    public function testEveryTargetOutOfScopeReportsTheSkippedOnes(): void
    {
        $this->editor->expects($this->never())->method('rewrite');

        $result = $this->tool(true)->execute([
            'old_path' => 'wysiwyg/old.jpg',
            'new_path' => 'wysiwyg/new.jpg',
            'scope' => 'cms_page',
        ]);

        $this->assertTrue($result->isError());
        $this->assertSame(self::TARGETS, $this->payload($result)['skipped_out_of_scope'] ?? null);
    }

    public function testTheGlobalPreviewListsTheOutOfScopeTargets(): void
    {
        $payload = $this->payload($this->tool(true)->execute([
            'old_path' => 'wysiwyg/old.jpg',
            'new_path' => 'wysiwyg/new.jpg',
            'scope' => 'global',
        ]));

        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertStringContainsString('skipped_out_of_scope', (string) json_encode($payload));
    }

    /**
     * Image replace for a caller allowed to save every content type; $readOnly = every target is outside its scope.
     *
     * @param bool $readOnly
     * @return ImageReplace
     */
    private function tool(bool $readOnly = false): ImageReplace
    {
        $files = $this->createMock(MediaFileInfo::class);
        $files->method('exists')->willReturn(true);
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('allowedTypes')->willReturnArgument(0);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('partitionByScope')->willReturnCallback(
            static fn (array $targets): array => $readOnly
                ? ['writable' => [], 'read_only' => $targets]
                : ['writable' => $targets, 'read_only' => []]
        );
        $scopeFilter = $this->createMock(ContentScopeFilter::class);
        $scopeFilter->method('filter')->willReturnCallback(
            static fn (array $items): array => ['visible' => $items, 'hidden' => 0]
        );
        $scopeFilter->method('withHidden')->willReturnArgument(0);
        return new ImageReplace($this->editor, $files, $acl, $locator, $scopeFilter);
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
