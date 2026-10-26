<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class ImageUsageIndexTest extends TestCase
{
    /**
     * @return void
     */
    public function testUsagesOfManyRunsOneCatalogPassForAllPaths(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $likeFilters = [];
        $select->method('where')->willReturnCallback(
            function (string $condition) use ($select, &$likeFilters): Select {
                if (str_contains($condition, 'value LIKE \'%wysiwyg')) {
                    $likeFilters[] = $condition;
                }
                return $select;
            }
        );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, string $value): string => str_replace('?', "'" . $value . "'", $text)
        );
        $connection->method('fetchPairs')->willReturn(['72' => 'description']);
        // One description query per catalog table (product, category), not one per path.
        $connection->expects($this->exactly(2))->method('fetchAll')->willReturnOnConsecutiveCalls(
            [['entity_id' => '10', 'attribute_id' => '72', 'value' => 'a-and-b']],
            []
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('getDocuments')->willReturn([]);
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->method('getRows')->willReturn([]);
        $extractor = $this->createMock(ImageReferenceExtractor::class);
        $extractor->method('fromHtml')->willReturn([
            ['path' => 'wysiwyg/a.jpg', 'alt' => 'A', 'kind' => 'img'],
            ['path' => 'wysiwyg/b.jpg', 'alt' => null, 'kind' => 'img'],
            ['path' => 'wysiwyg/other.jpg', 'alt' => null, 'kind' => 'img'],
        ]);

        $index = new ImageUsageIndex($scanner, $contentIndex, $extractor, $resource);
        $usages = $index->usagesOfMany(['wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'wysiwyg/c.jpg']);

        $this->assertSame(['wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'wysiwyg/c.jpg'], array_keys($usages));
        $this->assertCount(1, $usages['wysiwyg/a.jpg']);
        $this->assertTrue($usages['wysiwyg/a.jpg'][0]['has_alt']);
        $this->assertSame('product', $usages['wysiwyg/b.jpg'][0]['source']);
        $this->assertFalse($usages['wysiwyg/b.jpg'][0]['has_alt']);
        $this->assertSame([], $usages['wysiwyg/c.jpg']);
        $this->assertCount(2, $likeFilters);
        $this->assertSame(2, substr_count($likeFilters[0], ' OR '));
    }

    /**
     * @return void
     */
    public function testManyPathsAreChunkedInsteadOfAnUnfilteredScan(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $likeFilters = [];
        $select->method('where')->willReturnCallback(
            function (string $condition) use ($select, &$likeFilters): Select {
                if (str_contains($condition, 'value LIKE \'%wysiwyg')) {
                    $likeFilters[] = $condition;
                }
                return $select;
            }
        );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, string $value): string => str_replace('?', "'" . $value . "'", $text)
        );
        // Attribute ids are read once per entity type, not once per chunk.
        $connection->expects($this->exactly(2))->method('fetchPairs')->willReturn(['72' => 'description']);
        // Two chunks (25 + 5 paths) per catalog table; the same row comes back for both product chunks.
        $row = ['entity_id' => '10', 'attribute_id' => '72', 'value' => 'html'];
        $connection->expects($this->exactly(4))->method('fetchAll')->willReturnOnConsecutiveCalls(
            [$row],
            [$row],
            [],
            []
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('getDocuments')->willReturn([]);
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->method('getRows')->willReturn([]);
        $extractor = $this->createMock(ImageReferenceExtractor::class);
        $extractor->method('fromHtml')->willReturn([
            ['path' => 'wysiwyg/img0.jpg', 'alt' => null, 'kind' => 'img'],
            ['path' => 'wysiwyg/img29.jpg', 'alt' => null, 'kind' => 'img'],
        ]);
        $paths = array_map(static fn (int $i): string => 'wysiwyg/img' . $i . '.jpg', range(0, 29));

        $usages = (new ImageUsageIndex($scanner, $contentIndex, $extractor, $resource))->usagesOfMany($paths);

        $this->assertCount(4, $likeFilters);
        $this->assertSame(24, substr_count($likeFilters[0], ' OR '));
        $this->assertSame(4, substr_count($likeFilters[1], ' OR '));
        $this->assertCount(1, $usages['wysiwyg/img0.jpg']);
        $this->assertCount(1, $usages['wysiwyg/img29.jpg']);
        $this->assertSame([], $usages['wysiwyg/img1.jpg']);
    }
}
