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
     * @var ImageList
     */
    private ImageList $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $inventory = $this->createMock(MediaInventory::class);
        $inventory->method('getGalleryAssets')->willReturn([]);
        $inventory->method('scanFolder')->willReturn(
            ['wysiwyg/d.png', 'wysiwyg/a.jpg', 'wysiwyg/c.jpg', 'wysiwyg/b.jpg']
        );
        $this->fileInfo = $this->createMock(MediaFileInfo::class);
        $this->fileInfo->method('describe')->willReturnCallback(static fn (string $path): array => [
            'path' => $path,
            'exists' => true,
            'size' => ['wysiwyg/a.jpg' => 1024, 'wysiwyg/b.jpg' => 4096, 'wysiwyg/c.jpg' => 2048][$path] ?? 0,
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
    public function testSizeSortDescribesCandidatesButSkipsTheFullIndex(): void
    {
        $this->usageIndex->expects($this->never())->method('all');
        $this->usageIndex->expects($this->once())->method('usagesOfMany')->with(['wysiwyg/b.jpg'])->willReturn([]);

        $result = $this->tool->execute(['types' => ['jpg'], 'limit' => 1]);

        $this->assertSame(3, $this->at($result, 'total'));
        $this->assertSame('wysiwyg/b.jpg', $this->at($result, 'items.0.path'));
        $this->assertSame(4, $this->at($result, 'items.0.size_kb'));
    }

    /**
     * @return void
     */
    public function testUsageFiltersBuildTheIndexOnce(): void
    {
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
