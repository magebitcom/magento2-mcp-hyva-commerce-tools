<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The list queries filter, count and paginate in SQL and never select the draft/published JSON.
 */
class ContentIndexListTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $conditions = [];

    /**
     * @var list<array<mixed>>
     */
    private array $columns = [];

    /**
     * @var Select&MockObject
     */
    private Select $select;

    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface $connection;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        foreach (['from', 'joinLeft', 'order', 'limit'] as $method) {
            $this->select->method($method)->willReturnCallback(function (mixed ...$args): Select {
                if (isset($args[1]) && is_array($args[1])) {
                    $this->columns[] = $args[1];
                }
                return $this->select;
            });
        }
        $this->select->method('columns')->willReturnCallback(function (mixed $columns): Select {
            $this->columns[] = (array) $columns;
            return $this->select;
        });
        $this->select->method('where')->willReturnCallback(function (string $condition, mixed $value = null): Select {
            $this->conditions[] = $value === null ? $condition : $this->quoteInto($condition, $value);
            return $this->select;
        });
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quoteInto')->willReturnCallback(
            fn (string $text, mixed $value): string => $this->quoteInto($text, $value)
        );
        $this->connection->method('quoteIdentifier')->willReturnCallback(static fn (string $id): string => "`$id`");
    }

    public function testFindIdsAppliesEveryFilterAndThePageInSql(): void
    {
        $this->select->expects($this->once())->method('limit')->with(50, 100);
        $this->connection->method('fetchCol')->willReturn(['3', '4']);

        $ids = $this->index()->findIds('cms_page', [
            'store_id' => 2,
            'visible_store_ids' => [0, 1, 2],
            'search' => '50%_off',
            'liveview_enabled' => true,
            'content' => ContentIndex::CONTENT_PUBLISHED,
            'exclude_ids' => [9],
        ], 50, 100);

        $this->assertSame([3, 4], $ids);
        $sql = implode("\n", $this->conditions);
        $this->assertStringContainsString('store_link.store_id IN (2,0)', $sql);
        $this->assertStringContainsString('store_link.store_id IN (0,1,2)', $sql);
        $this->assertStringContainsString("LIKE '%50\\%\\_off%'", $sql);
        $this->assertStringContainsString('IFNULL(h.is_liveview_enabled, 0) <> 0', $sql);
        $this->assertStringContainsString('TRIM(h.published_content)', $sql);
        $this->assertStringContainsString('e.page_id NOT IN (9)', $sql);
        $this->assertStringNotContainsString('draft_content', implode(' ', array_map(
            static fn (mixed $column): string => is_scalar($column) ? (string) $column : '',
            array_merge(...$this->columns)
        )));
    }

    public function testUnfilteredListAddsNoConditions(): void
    {
        $this->connection->method('fetchOne')->willReturn('17');
        $this->assertSame(17, $this->index()->countIds('cms_block', []));
        $this->assertSame([], $this->conditions);
    }

    public function testAnEmptyIdFilterMatchesNothing(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);
        $this->index()->findIds('cms_page', ['ids' => []]);
        $this->assertSame(['1 = 0'], $this->conditions);
    }

    /**
     * Index over the mocked connection.
     *
     * @return ContentIndex
     */
    private function index(): ContentIndex
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ContentIndex($resource);
    }

    /**
     * Minimal quoteInto for the assertions.
     *
     * @param string $text
     * @param mixed $value
     * @return string
     */
    private function quoteInto(string $text, mixed $value): string
    {
        $quoted = is_array($value)
            ? implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value))
            : (is_int($value) ? (string) $value : "'" . (string) (is_scalar($value) ? $value : '') . "'");
        return str_replace('?', $quoted, $text);
    }
}
