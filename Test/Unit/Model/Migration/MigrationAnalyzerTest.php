<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Migration;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationAnalyzer;
use PHPUnit\Framework\TestCase;

class MigrationAnalyzerTest extends TestCase
{
    /**
     * @var MigrationAnalyzer
     */
    private MigrationAnalyzer $analyzer;

    protected function setUp(): void
    {
        $extractor = new ImageReferenceExtractor(
            new ComponentTree($this->createMock(ComponentProvider::class)),
            $this->createMock(ImageAssetPathResolverInterface::class)
        );
        $this->analyzer = new MigrationAnalyzer($extractor);
    }

    public function testPageBuilderContentIsMappedAndScored(): void
    {
        $html = '<div data-content-type="row"><div data-content-type="heading"><h2>Hi</h2></div><div '
            . 'data-content-type="buttons"><div data-content-type="button-item"><a>Go</a></div></div><figure '
            . 'data-content-type="image"><img src="{{media url=wysiwyg/hero.jpg}}" alt="Hero"></figure><div '
            . 'data-content-type="slider"><div data-content-type="slide">x</div></div></div>';

        $result = $this->analyzer->analyze($html);

        $this->assertSame(MigrationAnalyzer::KIND_PAGE_BUILDER, $result['kind']);
        $this->assertSame(1, $result['sections']);
        $this->assertSame(1, $result['buttons']);
        $this->assertSame(1, $result['sliders']);
        $this->assertSame(1, $result['banners']);
        $this->assertSame(['wysiwyg/hero.jpg'], $result['images']);
        $this->assertSame(['button-item' => 1, 'buttons' => 1, 'slide' => 1, 'slider' => 1], $result['html_fallbacks']);
        $suggested = array_column($result['suggestions'], 'suggested_component', 'content_type');
        $this->assertSame('button', $suggested['button-item']);
        $this->assertSame('heading', $suggested['heading']);
        $this->assertSame(20, $result['complexity_score'], '7 elements + 3 x 4 HTML fallbacks + 1 image');
        $this->assertSame('medium', $result['complexity']);
    }

    public function testPlainWysiwyg(): void
    {
        $result = $this->analyzer->analyze('<p>Some text</p><img src="/media/wysiwyg/a.png">');
        $this->assertSame(MigrationAnalyzer::KIND_WYSIWYG, $result['kind']);
        $this->assertSame([], $result['content_types']);
        $this->assertSame(['wysiwyg/a.png'], $result['images']);
        $this->assertSame('low', $result['complexity']);
    }

    public function testEmptyContent(): void
    {
        $result = $this->analyzer->analyze('  ');
        $this->assertSame(MigrationAnalyzer::KIND_EMPTY, $result['kind']);
        $this->assertSame('none', $result['complexity']);
    }
}
