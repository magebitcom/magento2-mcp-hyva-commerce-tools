<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaInventory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MediaInventoryTest extends TestCase
{
    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface&MockObject $connection;

    /**
     * @var Select&MockObject
     */
    private Select&MockObject $select;

    /**
     * @var MediaInventory
     */
    private MediaInventory $inventory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('isTableExists')->willReturn(true);
        $this->connection->method('tableColumnExists')->willReturn(true);
        $this->connection->method('select')->willReturn($this->select);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->inventory = new MediaInventory($resource, $this->createMock(MediaFileInfo::class));
    }

    /**
     * @return void
     */
    public function testLookupByPathQueriesOnePathOnce(): void
    {
        $this->select->expects($this->once())->method('where')->with('path = ?', 'wysiwyg/a.jpg')->willReturnSelf();
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([$this->row(5, 'wysiwyg/a.jpg')]);

        $first = $this->inventory->getGalleryAsset('wysiwyg/a.jpg');
        $second = $this->inventory->getGalleryAsset('wysiwyg/a.jpg');

        $this->assertSame(5, $first['asset_id'] ?? null);
        $this->assertSame('wysiwyg/a.bak.jpg', $second['backup_image_path'] ?? null);
    }

    /**
     * @return void
     */
    public function testMissingPathIsMemoisedToo(): void
    {
        $this->select->method('where')->willReturnSelf();
        $this->connection->expects($this->once())->method('fetchAll')->willReturn([]);

        $this->assertNull($this->inventory->getGalleryAsset('wysiwyg/none.jpg'));
        $this->assertNull($this->inventory->getGalleryAsset('wysiwyg/none.jpg'));
    }

    /**
     * @return void
     */
    public function testFullMapIsReadOnceAndServesPathLookups(): void
    {
        $this->select->expects($this->never())->method('where');
        $this->connection->expects($this->once())->method('fetchAll')
            ->willReturn([$this->row(1, 'wysiwyg/a.jpg'), $this->row(2, 'wysiwyg/b.jpg')]);

        $this->assertCount(2, $this->inventory->getGalleryAssets());
        $this->assertCount(2, $this->inventory->getGalleryAssets());
        $this->assertSame(2, $this->inventory->getGalleryAsset('wysiwyg/b.jpg')['asset_id'] ?? null);
        $this->assertNull($this->inventory->getGalleryAsset('wysiwyg/c.jpg'));
    }

    /**
     * @param int $id
     * @param string $path
     * @return array
     * @phpstan-return array<string, string|null>
     */
    private function row(int $id, string $path): array
    {
        return ['id' => (string) $id, 'path' => $path, 'title' => 'T', 'description' => null, 'width' => '10',
            'height' => '20', 'size' => '300', 'content_type' => 'image/jpeg',
            'backup_image_path' => str_replace('.jpg', '.bak.jpg', $path)];
    }
}
