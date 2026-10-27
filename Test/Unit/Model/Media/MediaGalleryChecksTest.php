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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaSyncReport;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use PHPUnit\Framework\TestCase;

class MediaGalleryChecksTest extends TestCase
{
    use ContentScopeStub;

    public function testSyncReportListsBothDirections(): void
    {
        $report = (new MediaSyncReport())->compare(
            ['wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'wysiwyg/new.jpg'],
            ['wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'wysiwyg/gone.jpg'],
            5
        );
        $this->assertFalse($report['in_sync']);
        $this->assertSame(['wysiwyg/new.jpg'], $report['missing_in_db_sample']);
        $this->assertSame(['wysiwyg/gone.jpg'], $report['missing_on_disk_sample']);
        $this->assertSame([3, 3, 1, 1], [
            $report['files_on_disk'],
            $report['assets_in_db'],
            $report['missing_in_db'],
            $report['missing_on_disk'],
        ]);
        $this->assertTrue((new MediaSyncReport())->compare(['x.jpg'], ['x.jpg'])['in_sync']);
    }

    public function testImageReferencesAreClassifiedByAssetId(): void
    {
        $resolver = $this->createMock(ImageAssetPathResolverInterface::class);
        $resolver->method('resolvePathById')->willReturnMap([[16, 'wysiwyg/home.jpg'], [99, null]]);
        $resolver->method('resolveIdByPath')->willReturnMap([['wysiwyg/home.jpg', 16], ['wysiwyg/loose.jpg', null]]);
        $tree = new ComponentTree($this->createMock(ComponentProvider::class));
        $auditor = new LiteralUrlAuditor(
            $this->createMock(ContentScanner::class),
            $tree,
            new ImageReferenceExtractor($tree, $resolver),
            $resolver,
            $this->contentScope(null)
        );

        $categories = static fn (array $found): array => array_column($found, 'category');
        $this->assertSame(['id_backed'], $categories($auditor->classify(
            ['src' => 'wysiwyg/home.jpg', 'imageOptions' => ['id' => 16, 'path' => 'wysiwyg/home.jpg']]
        )));
        $this->assertSame(['stale_asset_id'], $categories($auditor->classify(
            ['src' => 'wysiwyg/old.jpg', 'imageOptions' => ['id' => 99]]
        )));
        $this->assertSame(
            ['literal_fixable'],
            $categories($auditor->classify(['src' => 'wysiwyg/home.jpg', 'alt' => '']))
        );
        $this->assertSame(['literal_not_in_gallery'], $categories($auditor->classify(['src' => 'wysiwyg/loose.jpg'])));
        $this->assertSame(['richtext_literal', 'richtext_literal'], $categories($auditor->classify(
            '<p><img src="/media/wysiwyg/a.jpg"> {{hyva_image src="wysiwyg/b.jpg"}}</p>'
        )));
        $this->assertSame([], $auditor->classify('<p>{{hyva_image id="16" src="wysiwyg/home.jpg"}}</p>'));
        $mixed = $auditor->classify('{{hyva_image id="16" src="wysiwyg/home.jpg"}} {{hyva_image src="wysiwyg/c.jpg"}}');
        $this->assertSame([['category' => 'richtext_literal', 'path' => 'wysiwyg/c.jpg']], $mixed);
    }
}
