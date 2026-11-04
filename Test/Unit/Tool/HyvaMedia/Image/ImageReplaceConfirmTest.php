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
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
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
        $this->assertTrue($payload['dry_run'] ?? null);
        $this->assertSame(self::TARGETS, $payload['affected'] ?? null);
        $this->assertSame(count(self::TARGETS), $payload['total'] ?? null);
        $this->assertSame([], Cast::array($payload['details'] ?? [])['not_editable_usages'] ?? null);
        $this->assertStringContainsString('DRAFT', Cast::string($payload['hint'] ?? ''));
        $this->assertStringContainsString('hyva_cms_publish', Cast::string($payload['hint'] ?? ''));
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

    public function testAOneTypeScopeWritesOnTheFirstCallAndSaysSo(): void
    {
        $this->editor->expects($this->once())->method('rewrite')->willReturn([]);

        $payload = $this->payload($this->tool()->execute([
            'old_path' => 'wysiwyg/old.jpg',
            'new_path' => 'wysiwyg/new.jpg',
            'scope' => 'cms_page',
        ]));

        // T-152: no confirm needed (drafts only), and the reply says it wrote.
        $this->assertArrayNotHasKey('confirmation_required', $payload);
        $this->assertFalse($payload['dry_run'] ?? null);
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

    /**
     * T-89: page 7 uses the image in its Hyvä content and its Magento CMS content; it is listed once, under affected.
     */
    public function testTheGlobalPreviewListsEachEntityOnce(): void
    {
        $magentoUsage = $this->usage('magento_cms', 'cms_page', 7, 'sale');
        $productUsage = $this->usage('product', 'product', 12, 'MH01');
        $this->editor = $this->createMock(ImageContentEditor::class);
        $this->editor->method('normalize')->willReturnArgument(0);
        $this->editor->method('findTargets')
            ->willReturn(['editable' => self::TARGETS, 'not_editable' => [$magentoUsage, $productUsage]]);

        $payload = $this->payload($this->tool()->execute([
            'old_path' => 'wysiwyg/old.jpg',
            'new_path' => 'wysiwyg/new.jpg',
            'scope' => 'global',
        ]));

        $this->assertSame(
            [self::TARGETS[0] + ['not_editable_usages' => [$magentoUsage]], self::TARGETS[1]],
            $payload['affected'] ?? null
        );
        $this->assertSame(2, $payload['total'] ?? null);
        $this->assertSame([$productUsage], Cast::array($payload['details'] ?? [])['not_editable_usages'] ?? null);
        $this->assertStringContainsString('reported, not changed', Cast::string($payload['hint'] ?? ''));
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
        return new ImageReplace(
            $this->createMock(FeatureCheckerInterface::class),
            $this->editor,
            $files,
            $acl,
            $locator,
            $scopeFilter
        );
    }

    /**
     * Image usage row as ImageUsageIndex returns it.
     *
     * @param string $source
     * @param string $type
     * @param int $id
     * @param string $identifier
     * @return array
     * @phpstan-return array{source: string, entity_type: string, id: int, identifier: string, edition: string,
     *     uid: string|null, component: string|null, field: string|null, alt: string|null, has_alt: bool}
     */
    private function usage(string $source, string $type, int $id, string $identifier): array
    {
        return ['source' => $source, 'entity_type' => $type, 'id' => $id, 'identifier' => $identifier,
            'edition' => 'live', 'uid' => null, 'component' => null, 'field' => null, 'alt' => null,
            'has_alt' => false];
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
