<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageUpdateAlt;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * hyva_media.image.update_alt writes drafts without confirm and says it wrote (T-152).
 */
class ImageUpdateAltTest extends TestCase
{
    private const TARGETS = [['entity_type' => 'cms_page', 'id' => 13, 'identifier' => 'sale']];

    public function testItWritesTheDraftsOnTheFirstCallAndSaysSo(): void
    {
        $editor = $this->editor();
        $editor->expects($this->once())->method('rewrite')
            ->willReturn([['entity_type' => 'cms_page', 'id' => 13, 'changed' => true]]);

        $payload = $this->payload($this->tool($editor)->execute([
            'path' => 'wysiwyg/a.jpg',
            'alt' => 'A',
            'scope' => 'cms_page',
            'entity_ids' => [13],
        ]));

        $this->assertFalse($payload['dry_run'] ?? null);
        $this->assertFalse($payload['published'] ?? null);
        $this->assertCount(1, Cast::array($payload['drafts'] ?? null));
    }

    public function testTheSchemaAcceptsConfirmSoAPreviewMindedCallIsNoSchemaError(): void
    {
        $schema = $this->tool($this->editor())->getInputSchema();
        $properties = Cast::array($schema['properties'] ?? null);

        $this->assertArrayHasKey('confirm', $properties);
        $this->assertSame('boolean', Cast::array($properties['confirm'])['type'] ?? null);
        $this->assertNotContains('confirm', Cast::array($schema['required'] ?? []));
    }

    /**
     * Editor that finds the image on page 13 only.
     *
     * @return ImageContentEditor&MockObject
     */
    private function editor(): ImageContentEditor
    {
        $editor = $this->createMock(ImageContentEditor::class);
        $editor->method('normalize')->willReturnArgument(0);
        $editor->method('findTargets')->willReturn(['editable' => self::TARGETS, 'not_editable' => []]);
        $editor->method('altRewrite')->willReturn(static fn (array $node): array => $node);
        return $editor;
    }

    /**
     * Update-alt tool for an unrestricted role that may save every content type.
     *
     * @param ImageContentEditor $editor
     * @return ImageUpdateAlt
     */
    private function tool(ImageContentEditor $editor): ImageUpdateAlt
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('allowedTypes')->willReturnArgument(0);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('partitionByScope')->willReturnCallback(
            static fn (array $targets): array => ['writable' => $targets, 'read_only' => []]
        );
        $scopeFilter = $this->createMock(ContentScopeFilter::class);
        $scopeFilter->method('filter')->willReturnCallback(
            static fn (array $items): array => ['visible' => $items, 'hidden' => 0]
        );
        $scopeFilter->method('withHidden')->willReturnArgument(0);
        return new ImageUpdateAlt(
            $this->createMock(FeatureCheckerInterface::class),
            $editor,
            $acl,
            $locator,
            $scopeFilter
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
