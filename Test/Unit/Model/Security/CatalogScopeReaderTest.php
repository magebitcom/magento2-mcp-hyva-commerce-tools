<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security;

use Magebit\McpHyvaCommerceTools\Model\Security\CatalogScopeReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Product websites and category paths are read in one query for all ids.
 */
class CatalogScopeReaderTest extends TestCase
{
    public function testProductWebsitesAreReadInOneQuery(): void
    {
        $connection = $this->connection();
        $connection->expects($this->once())->method('fetchAll')->willReturn([
            ['entity_id' => '2', 'website_id' => '1'],
            ['entity_id' => '2', 'website_id' => '2'],
            ['entity_id' => '7', 'website_id' => null],
        ]);

        $result = $this->reader($connection)->productWebsiteIds([2, 7, 9, 2]);

        // Product 7 is in no website, product 9 does not exist.
        $this->assertSame([2 => [1, 2], 7 => []], $result);
    }

    public function testCategoryPathsAreReadInOneQuery(): void
    {
        $connection = $this->connection();
        $connection->expects($this->once())->method('fetchPairs')->willReturn(['4' => '1/11/4']);

        $this->assertSame([4 => '1/11/4'], $this->reader($connection)->categoryPaths([4, 99]));
    }

    public function testNoIdsNoQuery(): void
    {
        $connection = $this->connection();
        $connection->expects($this->never())->method('fetchAll');
        $connection->expects($this->never())->method('fetchPairs');
        $reader = $this->reader($connection);

        $this->assertSame([], $reader->productWebsiteIds([]));
        $this->assertSame([], $reader->categoryPaths([]));
    }

    /**
     * Connection whose selects accept any clause.
     *
     * @return AdapterInterface&MockObject
     */
    private function connection(): AdapterInterface&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        return $connection;
    }

    /**
     * Reader over the connection.
     *
     * @param AdapterInterface $connection
     * @return CatalogScopeReader
     */
    private function reader(AdapterInterface $connection): CatalogScopeReader
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new CatalogScopeReader($resource);
    }
}
