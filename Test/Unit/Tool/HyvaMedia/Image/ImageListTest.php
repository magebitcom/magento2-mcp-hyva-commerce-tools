<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaInventory;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageList;
use PHPUnit\Framework\MockObject\MockObject;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use PHPUnit\Framework\TestCase;

class ImageListTest extends TestCase
{
    use ToolPayloadTrait;

    /**
     * @var MediaFileInfo&MockObject
     */
    private MediaFileInfo&MockObject $fileInfo;

    /**
     * @var ImageUsageIndex&MockObject
     */
    private ImageUsageIndex&MockObject $usageIndex;

    /**
     * @var MediaInventory&MockObject
     */
    private MediaInventory&MockObject $inventory;

    /**
     * @var ImageList
     */
    private ImageList $tool;

    /**
     * @var array
     * @phpstan-var array<string, int>
     */
    private array $sizes = ['wysiwyg/a.jpg' => 1024, 'wysiwyg/b.jpg' => 4096, 'wysiwyg/c.jpg' => 2048];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->inventory = $this->createMock(MediaInventory::class);
        $inventory = $this->inventory;
        $inventory->method('scanFolder')->willReturn(
            ['wysiwyg/d.png', 'wysiwyg/a.jpg', 'wysiwyg/c.jpg', 'wysiwyg/b.jpg']
        );
        $this->fileInfo = $this->createMock(MediaFileInfo::class);
        $this->fileInfo->method('size')->willReturnCallback(fn (string $path): int => $this->sizes[$path] ?? 0);
        $this->fileInfo->method('describe')->willReturnCallback(fn (string $path): array => [
            'path' => $path,
            'exists' => true,
            'size' => $this->sizes[$path] ?? 0,
            'width' => 10,
            'height' => 10,
            'mime' => 'image/jpeg',
            'modified_at' => null,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ]);
        $this->usageIndex = $this->createMock(ImageUsageIndex::class);
        $this->tool = new ImageList($inventory, $this->fileInfo, $this->usageIndex);
    }

    /**
     * @return void
     */
    public function testWithoutUsageFiltersOnlyThePageIsDescribedAndLookedUp(): void
    {
        $this->inventory->method('getGalleryAssets')->willReturn([]);
        $this->usageIndex->expects($this->never())->method('all');
        $this->usageIndex->expects($this->once())->method('usagesOfMany')->with(['wysiwyg/a.jpg', 'wysiwyg/b.jpg'])
            ->willReturn(['wysiwyg/b.jpg' => [$this->usage(false)]]);
        $this->fileInfo->expects($this->exactly(2))->method('describe');

        $result = $this->tool->execute(['types' => ['JPG'], 'sort' => 'path', 'limit' => 2]);

        $this->assertSame(3, $this->at($result, 'total'));
        $items = $this->arrayAt($result, 'items');
        $this->assertSame(['wysiwyg/a.jpg', 'wysiwyg/b.jpg'], array_column($items, 'path'));
        $this->assertSame([false, true], array_column($items, 'used'));
        $this->assertSame([0, 1], array_column($items, 'usages_missing_alt'));
    }

    /**
     * @return void
     */
    public function testDefaultSizeSortDescribesOnlyThePage(): void
    {
        $this->inventory->method('getGalleryAssets')->willReturn([]);
        $this->usageIndex->expects($this->never())->method('all');
        $this->usageIndex->expects($this->once())->method('usagesOfMany')->with(['wysiwyg/b.jpg'])->willReturn([]);
        $this->fileInfo->expects($this->exactly(3))->method('size');
        $this->fileInfo->expects($this->once())->method('describe')->with('wysiwyg/b.jpg');

        $result = $this->tool->execute(['types' => ['jpg'], 'limit' => 1]);

        $this->assertSame(3, $this->at($result, 'total'));
        $this->assertSame('wysiwyg/b.jpg', $this->at($result, 'items.0.path'));
        $this->assertSame(4, $this->at($result, 'items.0.size_kb'));
    }

    /**
     * @return void
     */
    public function testSizeSortPrefersTheGallerySizeColumnOverAStat(): void
    {
        $this->inventory->method('getGalleryAssets')->willReturn(['wysiwyg/a.jpg' => [
            'asset_id' => 7, 'title' => 'A', 'description' => null, 'width' => 10, 'height' => 10,
            'size' => 9216, 'content_type' => 'image/jpeg', 'backup_image_path' => null,
        ]]);
        $this->usageIndex->method('usagesOfMany')->willReturn([]);
        $this->fileInfo->expects($this->exactly(2))->method('size')
            ->with($this->logicalNot($this->equalTo('wysiwyg/a.jpg')));

        $result = $this->tool->execute(['types' => ['jpg'], 'min_size_kb' => 5, 'limit' => 5]);

        $this->assertSame(1, $this->at($result, 'total'));
        $this->assertSame('wysiwyg/a.jpg', $this->at($result, 'items.0.path'));
        $this->assertSame(7, $this->at($result, 'items.0.gallery_asset_id'));
    }

    /**
     * @return void
     */
    public function testDimensionFiltersDescribeEveryCandidateOnce(): void
    {
        $this->inventory->method('getGalleryAssets')->willReturn([]);
        $this->usageIndex->method('usagesOfMany')->willReturn([]);
        $this->fileInfo->expects($this->exactly(3))->method('describe');
        $this->fileInfo->expects($this->never())->method('size');

        $result = $this->tool->execute(['types' => ['jpg'], 'min_width' => 5, 'limit' => 2]);

        $this->assertSame(3, $this->at($result, 'total'));
        $this->assertSame(['wysiwyg/b.jpg', 'wysiwyg/c.jpg'], array_column($this->arrayAt($result, 'items'), 'path'));
    }

    /**
     * @return void
     */
    public function testUsageFiltersBuildTheIndexOnce(): void
    {
        $this->inventory->method('getGalleryAssets')->willReturn([]);
        $this->usageIndex->expects($this->once())->method('all')->willReturn([
            'wysiwyg/a.jpg' => [$this->usage(true)],
            'wysiwyg/c.jpg' => [$this->usage(false)],
        ]);
        $this->usageIndex->expects($this->never())->method('usagesOfMany');

        $result = $this->tool->execute(['missing_alt' => true, 'sort' => 'path']);

        $this->assertSame(1, $this->at($result, 'total'));
        $this->assertSame('wysiwyg/c.jpg', $this->at($result, 'items.0.path'));
        $this->assertSame(['hyva_cms'], $this->at($result, 'items.0.used_in'));
    }

    /**
     * @param bool $hasAlt
     * @return array
     * @phpstan-return array{source: string, entity_type: string, id: int, identifier: string, edition: string,
     *     uid: string|null, component: string|null, field: string|null, alt: string|null, has_alt: bool}
     */
    private function usage(bool $hasAlt): array
    {
        return ['source' => 'hyva_cms', 'entity_type' => 'page', 'id' => 1, 'identifier' => 'home',
            'edition' => 'draft', 'uid' => null, 'component' => null, 'field' => null,
            'alt' => $hasAlt ? 'Alt' : null, 'has_alt' => $hasAlt];
    }
}
