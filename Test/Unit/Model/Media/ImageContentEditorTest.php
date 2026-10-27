<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImageContentEditorTest extends TestCase
{
    /**
     * @var ImageContentEditor
     */
    private ImageContentEditor $editor;

    /**
     * @var MockObject
     */
    private MockObject $usageIndex;

    protected function setUp(): void
    {
        $tree = new ComponentTree($this->createMock(ComponentProvider::class));
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtmlAttr')->willReturnCallback(
            static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES)
        );
        $this->usageIndex = $this->createMock(ImageUsageIndex::class);
        $this->editor = new ImageContentEditor(
            $this->usageIndex,
            $this->createMock(ContentService::class),
            $tree,
            new ImageReferenceExtractor($tree, $this->createMock(ImageAssetPathResolverInterface::class)),
            $escaper
        );
    }

    /**
     * @dataProvider textProvider
     */
    public function testReplacePathInTextMatchesWholeReferencesOnly(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->editor->replacePathInText($input, 'wysiwyg/a.jpg', 'wysiwyg/b.jpg'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function textProvider(): array
    {
        return [
            'img src' => ['<img src="/media/wysiwyg/a.jpg">', '<img src="/media/wysiwyg/b.jpg">'],
            'media directive' => ['{{media url="wysiwyg/a.jpg"}}', '{{media url="wysiwyg/b.jpg"}}'],
            'unquoted directive' => ['{{media url=wysiwyg/a.jpg}}', '{{media url=wysiwyg/b.jpg}}'],
            'css url' => ['url(wysiwyg/a.jpg)', 'url(wysiwyg/b.jpg)'],
            'query string' => ['/media/wysiwyg/a.jpg?v=2', '/media/wysiwyg/b.jpg?v=2'],
            'longer file name' => ['wysiwyg/a.jpg.bak', 'wysiwyg/a.jpg.bak'],
            'other folder' => ['other/wysiwyg/a.jpg', 'other/wysiwyg/a.jpg'],
        ];
    }

    public function testReplaceRewriteSwapsUrlEncodedReferences(): void
    {
        $rewrite = $this->editor->replaceRewrite('wysiwyg/my image.jpg', 'wysiwyg/new image.jpg', null);
        $node = $rewrite([
            'uid' => 'x',
            'component' => 'text',
            'children' => [],
            'text' => '<img src="/media/wysiwyg/my%20image.jpg"><img src="/media/wysiwyg/my%20image.jpg.bak">'
                . '{{media url="wysiwyg/my image.jpg"}}<img src="/media/wysiwyg%2Fmy%20image.jpg">',
        ]);

        $this->assertSame(
            '<img src="/media/wysiwyg/new%20image.jpg"><img src="/media/wysiwyg/my%20image.jpg.bak">'
                . '{{media url="wysiwyg/new image.jpg"}}<img src="/media/wysiwyg/new%20image.jpg">',
            $node['text']
        );
    }

    public function testReplaceRewriteSwapsImageFieldsAndHtml(): void
    {
        $rewrite = $this->editor->replaceRewrite('wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'New alt');
        $node = $rewrite([
            'uid' => 'x',
            'component' => 'image',
            'children' => [],
            'image' => ['src' => 'wysiwyg/a.jpg', 'alt' => '', 'preview_url' => 'https://s/media/wysiwyg/a.jpg',
                'imageOptions' => ['path' => 'wysiwyg/a.jpg', 'id' => 7]],
            'desktop_image' => ['src' => 'wysiwyg/a.jpg.bak', 'alt' => 'keep'],
            'text' => '<p><img src="/media/wysiwyg/a.jpg"></p>',
        ]);
        $image = $this->arr($node['image']);
        $this->assertSame('wysiwyg/b.jpg', $image['src']);
        $this->assertSame('New alt', $image['alt']);
        $this->assertSame('https://s/media/wysiwyg/b.jpg', $image['preview_url']);
        // The old Media Gallery asset id is dropped so saving backfills the new image's id from the path.
        $this->assertSame(['path' => 'wysiwyg/b.jpg'], $image['imageOptions']);
        $this->assertSame(['src' => 'wysiwyg/a.jpg.bak', 'alt' => 'keep'], $node['desktop_image']);
        $this->assertSame('<p><img src="/media/wysiwyg/b.jpg"></p>', $node['text']);
    }

    public function testAltRewriteSetsImageFieldsAndImgTags(): void
    {
        $rewrite = $this->editor->altRewrite('wysiwyg/a.jpg', 'Spring "hero"');
        $node = $rewrite([
            'uid' => 'x',
            'component' => 'text',
            'children' => [],
            'image' => ['src' => 'wysiwyg/a.jpg', 'alt' => ''],
            'text' => '<img src="/media/wysiwyg/a.jpg" alt="old"><img src="/media/wysiwyg/a.jpg"><img '
                . 'src="/media/other.jpg">',
        ]);
        $this->assertSame('Spring "hero"', $this->arr($node['image'])['alt']);
        $this->assertSame(
            '<img src="/media/wysiwyg/a.jpg" alt="Spring &quot;hero&quot;">'
            . '<img alt="Spring &quot;hero&quot;" src="/media/wysiwyg/a.jpg"><img src="/media/other.jpg">',
            $node['text']
        );
    }

    public function testFindTargetsSplitsHyvaAndNonEditableUsages(): void
    {
        $usage = static fn (string $source, string $type, int $id) => [
            'source' => $source, 'entity_type' => $type, 'id' => $id, 'identifier' => 'i' . $id, 'edition' => 'draft',
            'uid' => null, 'component' => null, 'field' => null, 'alt' => null, 'has_alt' => false,
        ];
        $this->usageIndex->method('usagesOf')->willReturn([
            $usage('hyva_cms', 'cms_page', 1),
            $usage('hyva_cms', 'cms_page', 1),
            $usage('hyva_cms', 'cms_block', 2),
            $usage('menu', 'menu', 3),
            $usage('product', 'product', 9),
        ]);
        $targets = $this->editor->findTargets('wysiwyg/a.jpg', ['cms_page', 'menu']);
        $this->assertSame(
            [
                ['entity_type' => 'cms_page', 'id' => 1, 'identifier' => 'i1'],
                ['entity_type' => 'menu', 'id' => 3, 'identifier' => 'i3'],
            ],
            $targets['editable']
        );
        $this->assertCount(1, $targets['not_editable']);
    }

    public function testAFailurePartWayNamesTheDraftsAlreadyRewritten(): void
    {
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('mapNodes')->willReturn(['content' => ['rewritten']]);
        $content = $this->createMock(ContentService::class);
        $content->method('getWorkingDocument')->willReturn(['content' => []]);
        $content->method('saveDraft')->willReturnCallback(
            static function (string $type, int $id): array {
                if ($id === 2) {
                    throw new LocalizedException(__('Draft #2 is locked.'));
                }
                return ['version_id' => 11];
            }
        );
        $editor = new ImageContentEditor(
            $this->createMock(ImageUsageIndex::class),
            $content,
            $tree,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(Escaper::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Drafts already rewritten before the failure: cms_page #1 (version 11).');
        $editor->rewrite([
            ['entity_type' => 'cms_page', 'id' => 1, 'identifier' => 'a'],
            ['entity_type' => 'cms_page', 'id' => 2, 'identifier' => 'b'],
        ], static fn (array $node): array => $node, 'Replace image');
    }

    public function testNormalizeRejectsTraversal(): void
    {
        $this->expectException(LocalizedException::class);
        $this->editor->normalize('../wysiwyg/a.jpg');
    }

    /**
     * @param mixed $value
     * @return array<mixed>
     */
    private function arr(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }
}
