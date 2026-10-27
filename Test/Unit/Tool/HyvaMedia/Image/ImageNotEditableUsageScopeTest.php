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
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageUpdateAlt;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Image replace and alt update list reported-only usages (Magento CMS, catalog) only for content the caller may see.
 */
class ImageNotEditableUsageScopeTest extends TestCase
{
    private const TARGET = ['entity_type' => 'cms_page', 'id' => 7, 'identifier' => 'sale'];

    /**
     * @var ImageContentEditor&MockObject
     */
    private ImageContentEditor $editor;

    /**
     * @var ContentScopeFilter&MockObject
     */
    private ContentScopeFilter $scopeFilter;

    protected function setUp(): void
    {
        $this->editor = $this->createMock(ImageContentEditor::class);
        $this->editor->method('normalize')->willReturnArgument(0);
        $this->editor->method('replaceRewrite')->willReturn(static fn (array $node): array => $node);
        $this->editor->method('altRewrite')->willReturn(static fn (array $node): array => $node);
        $this->editor->method('rewrite')->willReturn([]);
        // Product 5 is in the caller's scope, product 9 is not.
        $this->scopeFilter = $this->createMock(ContentScopeFilter::class);
        $this->scopeFilter->method('filter')->willReturnCallback(static function (array $items): array {
            $visible = array_values(array_filter($items, static fn (array $item): bool => $item['id'] !== 9));
            return ['visible' => $visible, 'hidden' => count($items) - count($visible)];
        });
        $this->scopeFilter->method('withHidden')->willReturnCallback(
            static fn (array $result, string $key, int $hidden): array => $result + [$key => $hidden]
        );
    }

    public function testTheReplacePreviewAndResultDropOutOfScopeUsages(): void
    {
        $this->targets([self::TARGET]);
        $tool = $this->replaceTool();
        $arguments = ['old_path' => 'wysiwyg/old.jpg', 'new_path' => 'wysiwyg/new.jpg', 'scope' => 'global'];

        $preview = $this->payload($tool->execute($arguments));
        $result = $this->payload($tool->execute($arguments + ['confirm' => true]));

        $this->assertStringNotContainsString('"id":9', (string) json_encode($preview));
        $this->assertStringContainsString('"usages_hidden_by_permissions":1', (string) json_encode($preview));
        $this->assertSame([5], array_column($this->listOf($result, 'not_changed'), 'id'));
        $this->assertSame(1, $result['usages_hidden_by_permissions'] ?? null);
    }

    public function testTheReplaceErrorWithoutTargetsDropsOutOfScopeUsages(): void
    {
        $this->targets([]);

        $payload = $this->payload($this->replaceTool()->execute(
            ['old_path' => 'wysiwyg/old.jpg', 'new_path' => 'wysiwyg/new.jpg', 'scope' => 'cms_page']
        ));

        $this->assertSame([5], array_column($this->listOf($payload, 'not_editable_usages'), 'id'));
        $this->assertSame('"wysiwyg/old.jpg" is not used in any cms_page Hyvä content.', $payload['error'] ?? null);
    }

    public function testTheAltUpdateDropsOutOfScopeUsagesWithAndWithoutTargets(): void
    {
        $this->targets([]);
        $arguments = ['path' => 'wysiwyg/old.jpg', 'alt' => 'Sale', 'scope' => 'cms_page'];

        $error = $this->payload($this->altTool()->execute($arguments));

        $this->assertSame([5], array_column($this->listOf($error, 'not_editable_usages'), 'id'));
        $this->assertSame(1, $error['usages_hidden_by_permissions'] ?? null);

        $this->setUp();
        $this->targets([self::TARGET]);
        $result = $this->payload($this->altTool()->execute($arguments));

        $this->assertSame([5], array_column($this->listOf($result, 'not_changed'), 'id'));
    }

    /**
     * Editor targets: the given Hyvä entities plus usages in products 5 and 9.
     *
     * @param array $editable
     * @return void
     * @phpstan-param list<array{entity_type: string, id: int, identifier: string}> $editable
     */
    private function targets(array $editable): void
    {
        $usages = [];
        foreach ([5, 9] as $id) {
            $usages[] = ['source' => 'product', 'entity_type' => 'product', 'id' => $id, 'identifier' => 'sku-' . $id,
                'edition' => 'live', 'uid' => null, 'component' => null, 'field' => 'description', 'alt' => 'Alt',
                'has_alt' => true];
        }
        $this->editor->method('findTargets')->willReturn(['editable' => $editable, 'not_editable' => $usages]);
    }

    /**
     * Image replace for a caller allowed to save every content type, every target writable.
     *
     * @return ImageReplace
     */
    private function replaceTool(): ImageReplace
    {
        $files = $this->createMock(MediaFileInfo::class);
        $files->method('exists')->willReturn(true);
        return new ImageReplace($this->editor, $files, $this->acl(), $this->locator(), $this->scopeFilter);
    }

    /**
     * Alt update for a caller allowed to save every content type, every target writable.
     *
     * @return ImageUpdateAlt
     */
    private function altTool(): ImageUpdateAlt
    {
        return new ImageUpdateAlt($this->editor, $this->acl(), $this->locator(), $this->scopeFilter);
    }

    /**
     * ACL allowing every content type.
     *
     * @return ContentAcl
     */
    private function acl(): ContentAcl
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('allowedTypes')->willReturnArgument(0);
        return $acl;
    }

    /**
     * Locator treating every target as writable.
     *
     * @return ContentLocator
     */
    private function locator(): ContentLocator
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('partitionByScope')->willReturnCallback(
            static fn (array $targets): array => ['writable' => $targets, 'read_only' => []]
        );
        return $locator;
    }

    /**
     * A list of rows from a payload.
     *
     * @param array $payload
     * @param string $key
     * @return array
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return list<array<string, mixed>>
     */
    private function listOf(array $payload, string $key): array
    {
        $list = $payload[$key] ?? null;
        $this->assertIsArray($list);
        /** @var list<array<string, mixed>> $list */
        return $list;
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
