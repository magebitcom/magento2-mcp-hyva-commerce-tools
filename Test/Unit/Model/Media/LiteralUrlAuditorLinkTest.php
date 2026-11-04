<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use PHPUnit\Framework\TestCase;

/**
 * T-160: a link value carries its target in src (custom_url, cms_page); it is not an image.
 */
class LiteralUrlAuditorLinkTest extends TestCase
{
    public function testACustomUrlMenuLinkIsNoImageFinding(): void
    {
        $link = ['type' => 'custom_url', 'value' => '/store-credit/account/', 'label' => 'Store credit',
            'open_in_new_tab' => false, 'prefix' => 'STORE_URL', 'suffix' => '', 'placeholder' => 'Store credit',
            'src' => '/store-credit/account/'];

        $this->assertSame([], $this->auditor()->classify($link));
    }

    public function testACmsPageLinkIsNoImageFinding(): void
    {
        $this->assertSame([], $this->auditor()->classify(['type' => 'cms_page', 'value' => 'about-us',
            'label' => 'About', 'src' => 'about-us']));
    }

    public function testAnImageValueIsStillClassified(): void
    {
        $this->assertSame(
            [['category' => 'literal_not_in_gallery', 'path' => 'wysiwyg/a.jpg', 'asset_id' => null]],
            $this->auditor()->classify(['src' => 'wysiwyg/a.jpg', 'alt' => 'A'])
        );
    }

    /**
     * Auditor whose gallery knows no path.
     *
     * @return LiteralUrlAuditor
     */
    private function auditor(): LiteralUrlAuditor
    {
        $tree = $this->createMock(ComponentTree::class);
        $resolver = $this->createMock(ImageAssetPathResolverInterface::class);
        $resolver->method('resolveIdByPath')->willReturn(null);
        return new LiteralUrlAuditor(
            $this->createMock(ContentScanner::class),
            $tree,
            new ImageReferenceExtractor($tree, $resolver),
            $resolver,
            $this->createMock(ContentScopeFilter::class)
        );
    }
}
