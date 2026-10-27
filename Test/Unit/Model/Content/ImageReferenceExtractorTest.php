<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ImageReferenceExtractorTest extends TestCase
{
    /**
     * @var ImageReferenceExtractor
     */
    private ImageReferenceExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ImageReferenceExtractor(
            new ComponentTree($this->createMock(ComponentProvider::class)),
            $this->createMock(ImageAssetPathResolverInterface::class)
        );
    }

    /**
     * Test normalize path.
     *
     * @dataProvider pathProvider
     */
    public function testNormalizePath(string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->extractor->normalizePath($input));
    }

    /**
     * Path provider.
     *
     * @return array
     * @phpstan-return array<string, array{0: string, 1: string|null}>
     */
    public static function pathProvider(): array
    {
        return [
            'relative' => ['wysiwyg/a.jpg', 'wysiwyg/a.jpg'],
            'media url' => ['https://shop.test/media/wysiwyg/b.PNG?v=2', 'wysiwyg/b.PNG'],
            'leading slash' => ['/media/catalog/c.webp', 'catalog/c.webp'],
            'pub media' => ['pub/media/wysiwyg/d.gif', 'wysiwyg/d.gif'],
            'encoded' => ['wysiwyg/my%20file.jpg', 'wysiwyg/my file.jpg'],
            'external' => ['https://cdn.example.com/x.jpg', null],
            'not an image' => ['wysiwyg/doc.pdf', null],
            'data uri' => ['data:image/png;base64,AAA', null],
        ];
    }

    public function testFromHtmlFindsImgDirectivesAndBackgrounds(): void
    {
        $html = '<p><img src="/media/wysiwyg/a.jpg" alt="A"></p><img src="{{media '
            . 'url=&quot;wysiwyg/b.png&quot;}}"><div style="background-image: '
            . 'url(\'/media/wysiwyg/c.webp\')"></div>{{media url="wysiwyg/d.jpg"}}';
        $refs = $this->extractor->fromHtml($html);

        $byPath = array_column($refs, null, 'path');
        $this->assertSame('A', $byPath['wysiwyg/a.jpg']['alt']);
        $this->assertSame('html_img', $byPath['wysiwyg/a.jpg']['kind']);
        $this->assertNull($byPath['wysiwyg/b.png']['alt']);
        $this->assertSame('css_background', $byPath['wysiwyg/c.webp']['kind']);
        $this->assertSame('media_directive', $byPath['wysiwyg/d.jpg']['kind']);
    }

    public function testFromDocumentReadsImageFieldsAndHtmlFields(): void
    {
        $document = ['content' => [
            'r' => ['uid' => 'r', 'component' => 'row', 'children' => [
                [
                    'uid' => 'i',
                    'component' => 'image',
                    'children' => [],
                    'image' => ['src' => 'wysiwyg/hero.jpg', 'alt' => ''],
                    'desktop_image' => ['src' => 'wysiwyg/hero-d.jpg', 'alt' => 'Hero'],
                ],
                [
                    'uid' => 't',
                    'component' => 'text',
                    'children' => [],
                    'text' => '<p><img src="/media/wysiwyg/inline.png" alt="x"></p>',
                ],
            ]],
        ]];
        $refs = $this->extractor->fromDocument($document);

        $this->assertCount(3, $refs);
        $this->assertSame(
            ['wysiwyg/hero.jpg', 'wysiwyg/hero-d.jpg', 'wysiwyg/inline.png'],
            array_column($refs, 'path')
        );
        $this->assertFalse($refs[0]['has_alt']);
        $this->assertSame('image', $refs[0]['field']);
        $this->assertTrue($refs[1]['has_alt']);
        $this->assertSame('t', $refs[2]['uid']);
    }

    public function testImageFieldRendersItsMediaGalleryAssetFirst(): void
    {
        $resolver = $this->createMock(ImageAssetPathResolverInterface::class);
        $resolver->method('resolvePathById')->willReturnMap([[12, 'wysiwyg/renamed/hero.jpg'], [99, null]]);
        $extractor = new ImageReferenceExtractor(
            new ComponentTree($this->createMock(ComponentProvider::class)),
            $resolver
        );

        $this->assertSame('wysiwyg/renamed/hero.jpg', $extractor->imageFieldPath(
            ['src' => 'wysiwyg/hero.jpg', 'imageOptions' => ['id' => 12, 'path' => 'wysiwyg/hero.jpg']]
        ));
        $this->assertSame('wysiwyg/options.jpg', $extractor->imageFieldPath(
            ['src' => 'wysiwyg/src.jpg', 'imageOptions' => ['id' => 99, 'path' => 'wysiwyg/options.jpg']]
        ), 'An id that no longer resolves falls back to imageOptions.path.');
        $this->assertSame('wysiwyg/src.jpg', $extractor->imageFieldPath(['src' => 'wysiwyg/src.jpg']));

        $references = $extractor->fromHtml('<p>{{hyva_image id="12" src="wysiwyg/hero.jpg" alt="Hero"}}</p>');
        $this->assertSame(
            [['path' => 'wysiwyg/renamed/hero.jpg', 'alt' => 'Hero', 'kind' => 'hyva_image']],
            $references
        );
    }

    public function testAssetIdsArePrefetchedInOneBatch(): void
    {
        /** @var PrefetchingImageAssetPathResolver&MockObject $resolver */
        $resolver = $this->createMock(PrefetchingImageAssetPathResolver::class);
        $resolver->expects($this->once())->method('prefetchPathsByIds')->with([12, 7, 3]);
        $extractor = new ImageReferenceExtractor(
            new ComponentTree($this->createMock(ComponentProvider::class)),
            $resolver
        );
        $extractor->prefetchAssetIds([
            ['content' => ['a' => ['image' => ['src' => 'x.jpg', 'imageOptions' => ['id' => 12]],
                'text' => '<p>{{hyva_image id="7" src="y.jpg"}} {{hyva_image id=&quot;3&quot; '
                    . 'src=&quot;z.jpg&quot;}}</p>',
                'children' => [['image' => ['src' => 'x.jpg', 'imageOptions' => ['id' => 12]]]]]]],
            ['content' => []],
        ]);
    }
}
