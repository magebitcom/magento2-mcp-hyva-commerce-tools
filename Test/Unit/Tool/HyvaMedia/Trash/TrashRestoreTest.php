<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Trash;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Trash\TrashRestore;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TrashRestoreTest extends TestCase
{
    use ToolPayloadTrait;

    /**
     * A folder at the original path blocks the restore, as Hyvä's isExist() check would skip the move but still
     * drop the trash row.
     *
     * @return void
     */
    public function testAFolderAtTheOriginalPathBlocksTheRestore(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('trashList')->willReturn(['items' => [
            ['id' => 1, 'path' => 'wysiwyg/a.jpg'],
            ['id' => 2, 'path' => 'wysiwyg/b.jpg'],
        ], 'total' => 2, 'retention_days' => 30]);
        $gallery->method('fileExists')->willReturn(false);
        $gallery->method('pathExists')->willReturnCallback(static fn (string $path): bool => $path === 'wysiwyg/b.jpg');
        $gallery->expects($this->once())->method('restore')->with([1])->willReturn(1);

        $tool = new TrashRestore(
            $featureChecker,
            $gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageUsageIndex::class),
            $this->createMock(CallerContext::class),
            $this->createMock(LiteralUrlAuditor::class)
        );
        $result = $tool->execute(['trash_ids' => [1, 2]]);

        $this->assertSame(1, $this->payloadAt($result, 'restored'));
        $this->assertSame(2, $this->payloadAt($result, 'not_restored.0.trash_id'));
    }

    /**
     * T-159: the restored asset's new id comes back with the image fields that still hold the old one.
     *
     * @return void
     */
    public function testTheReplyGivesTheNewIdAndTheReferencesToTheOldOne(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('trashList')->willReturn(['items' => [['id' => 1, 'path' => 'wysiwyg/a.jpg']],
            'total' => 1, 'retention_days' => 30]);
        $gallery->method('pathExists')->willReturn(false);
        $gallery->method('restore')->willReturn(1);
        $gallery->method('getByPath')->with('wysiwyg/a.jpg')->willReturn(['id' => 43, 'path' => 'wysiwyg/a.jpg',
            'title' => 'a', 'content_type' => 'image/jpeg', 'width' => 1, 'height' => 1, 'size' => 1,
            'updated_at' => '2026-11-04 15:11:14']);
        $reference = ['entity_type' => 'cms_page', 'id' => 13, 'identifier' => 'sale', 'uid' => 'u1',
            'component' => 'image', 'field' => 'image', 'path' => 'wysiwyg/a.jpg', 'asset_id' => 26];
        $auditor = $this->createMock(LiteralUrlAuditor::class);
        $auditor->expects($this->once())->method('staleReferences')->with(['wysiwyg/a.jpg'])
            ->willReturn(['wysiwyg/a.jpg' => [$reference, $reference]]);

        $result = (new TrashRestore(
            $featureChecker,
            $gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageUsageIndex::class),
            $this->createMock(CallerContext::class),
            $auditor
        ))->execute(['trash_ids' => [1]]);

        $this->assertSame(43, $this->payloadAt($result, 'assets.0.asset_id'));
        $this->assertSame([26], $this->payloadAt($result, 'assets.0.previous_asset_ids'));
        $this->assertSame(13, $this->payloadAt($result, 'assets.0.stale_references.0.id'));
        $this->assertSame(TrashRestore::NEW_ID_NOTE, $this->payloadAt($result, 'note'));
    }

    /**
     * The files are back when the content scan runs: a scan failure leaves stale_references empty and warns.
     *
     * @return void
     */
    public function testAFailedContentScanStillReportsTheRestore(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('trashList')->willReturn(['items' => [['id' => 1, 'path' => 'wysiwyg/a.jpg']],
            'total' => 1, 'retention_days' => 30]);
        $gallery->method('pathExists')->willReturn(false);
        $gallery->expects($this->once())->method('restore')->with([1])->willReturn(1);
        $gallery->method('getByPath')->willReturn(['id' => 43, 'path' => 'wysiwyg/a.jpg', 'title' => 'a',
            'content_type' => 'image/jpeg', 'width' => 1, 'height' => 1, 'size' => 1,
            'updated_at' => '2026-11-04 15:11:14']);
        $auditor = $this->createMock(LiteralUrlAuditor::class);
        $auditor->method('staleReferences')->willThrowException(new LocalizedException(__('Broken document.')));

        $result = (new TrashRestore(
            $featureChecker,
            $gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageUsageIndex::class),
            $this->createMock(CallerContext::class),
            $auditor
        ))->execute(['trash_ids' => [1]]);

        $this->assertFalse($result->isError());
        $this->assertSame(1, $this->payloadAt($result, 'restored'));
        $this->assertSame(43, $this->payloadAt($result, 'assets.0.asset_id'));
        $this->assertSame([], $this->payloadAt($result, 'assets.0.stale_references'));
        $this->assertSame([], $this->payloadAt($result, 'assets.0.previous_asset_ids'));
        $warning = $this->payloadAt($result, 'warning');
        $this->assertIsString($warning);
        $this->assertStringContainsString('Broken document.', $warning);
    }
}
