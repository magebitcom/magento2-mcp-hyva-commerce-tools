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
use Magebit\McpHyvaCommerceTools\Exception\OutOfScopeException;
use Magebit\McpHyvaCommerceTools\Exception\PartialRewriteException;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageUpdateAlt;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use PHPUnit\Framework\TestCase;

/**
 * hyva_media.image.update_alt writes drafts without confirm and says it wrote (T-152).
 */
class ImageUpdateAltTest extends TestCase
{
    use RefusalAssertTrait;

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

    public function testARoleThatMayNotSaveTheTypeIsRefusedWithTheResource(): void
    {
        $editor = $this->editor();
        $editor->expects($this->never())->method('rewrite');
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('allowedTypes')->willReturn([]);
        $acl->method('resources')->with(['cms_page'], [ContentAcl::VERB_SAVE])->willReturn(['Hyva_Cms::page_save']);
        $tool = $this->tool($editor, $acl);

        $refusal = $this->assertRefused(
            fn () => $tool->execute(['path' => 'wysiwyg/a.jpg', 'alt' => 'A', 'scope' => 'cms_page']),
            'acl'
        );
        $this->assertSame('Your role may not save cms_page drafts.', $refusal->getMessage());
        $this->assertSame('Hyva_Cms::page_save', $refusal->getData()['resource'] ?? null);
    }

    /**
     * T-172: a scope refusal after a draft was saved answers softly, naming the saved draft, with reason "scope".
     */
    public function testARefusalAfterADraftWasSavedAnswersSoftlyWithTheReason(): void
    {
        $saved = [['entity_type' => 'cms_page', 'id' => 13, 'identifier' => 'sale', 'changed' => true,
            'version_id' => 5]];
        $failed = ['entity_type' => 'cms_block', 'id' => 4, 'identifier' => 'promo'];
        $editor = $this->editor();
        $editor->method('rewrite')->willThrowException(new PartialRewriteException(
            __('Out of scope (at cms_block #4). Drafts already rewritten before the failure: cms_page #13 (version 5).'),
            new OutOfScopeException(__('Out of scope.'), ['denied_store_ids' => [2]]),
            $failed,
            $saved
        ));

        $result = $this->tool($editor)->execute(['path' => 'wysiwyg/a.jpg', 'alt' => 'A', 'scope' => 'all']);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('cms_page #13 (version 5)', Cast::string($payload['error'] ?? ''));
        $this->assertSame('scope', $payload['reason'] ?? null);
        $this->assertSame([2], $payload['denied_store_ids'] ?? null);
        $this->assertSame($saved, $payload['drafts_saved'] ?? null);
        $this->assertSame($failed, $payload['failed_at'] ?? null);
        $this->assertFalse($payload['published'] ?? null);
    }

    /**
     * A non-refusal failure after a draft was saved answers softly too, without a reason.
     */
    public function testAFailureAfterADraftWasSavedAnswersSoftlyWithoutAReason(): void
    {
        $editor = $this->editor();
        $editor->method('rewrite')->willThrowException(new PartialRewriteException(
            __('Locked (at cms_block #4). Drafts already rewritten before the failure: cms_page #13 (version 5).'),
            new LocalizedException(__('Locked.')),
            ['entity_type' => 'cms_block', 'id' => 4, 'identifier' => 'promo'],
            [['entity_type' => 'cms_page', 'id' => 13, 'identifier' => 'sale', 'changed' => true, 'version_id' => 5]]
        ));

        $result = $this->tool($editor)->execute(['path' => 'wysiwyg/a.jpg', 'alt' => 'A', 'scope' => 'all']);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertArrayNotHasKey('reason', $payload);
        $this->assertCount(1, Cast::array($payload['drafts_saved'] ?? null));
    }

    /**
     * Nothing saved: the refusal stays a hard -32004 with its reason.
     */
    public function testARefusalBeforeAnyDraftWasSavedIsAHardRefusal(): void
    {
        $editor = $this->editor();
        $editor->method('rewrite')->willThrowException(
            new OutOfScopeException(__('Out of scope.'), ['denied_store_ids' => [2]])
        );
        $tool = $this->tool($editor);

        $refusal = $this->assertRefused(
            fn () => $tool->execute(['path' => 'wysiwyg/a.jpg', 'alt' => 'A', 'scope' => 'all']),
            'scope'
        );
        $this->assertSame([2], $refusal->getData()['denied_store_ids'] ?? null);
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
     * @param ContentAcl|null $acl Default: may save every content type
     * @return ImageUpdateAlt
     */
    private function tool(ImageContentEditor $editor, ?ContentAcl $acl = null): ImageUpdateAlt
    {
        if ($acl === null) {
            $acl = $this->createMock(ContentAcl::class);
            $acl->method('allowedTypes')->willReturnArgument(0);
        }
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
