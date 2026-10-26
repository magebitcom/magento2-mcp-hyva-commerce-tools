<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Audit;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SimpleXMLElement;

/**
 * The MCP Activity widget counts writes and destructive actions from the tool registry, not from a fixed list.
 */
class McpActivityReaderTest extends TestCase
{
    /**
     * @var array<string, bool> name => is write, as this module registers its tools in etc/di.xml
     */
    private static array $moduleTools = [];

    public function testEveryRegisteredWriteToolOfThisModuleIsCounted(): void
    {
        $tools = self::moduleTools();
        $expected = array_keys(array_filter($tools));
        $this->assertGreaterThan(20, count($expected), 'the module registers its write tools in etc/di.xml');
        $tools['cms.page.delete'] = true;
        $tools['system.store_info'] = false;

        $quoted = null;
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->method('quoteInto')->willReturnCallback(
            static function (string $text, mixed $value) use (&$quoted): string {
                $quoted = $value;
                return $text;
            }
        );
        $connection->method('fetchRow')->willReturn(['calls' => 9, 'errors' => 1, 'writes' => 4, 'admins' => 2]);
        $reader = $this->reader($tools, $connection);

        $totals = $reader->getTotals('2026-01-01 00:00:00');

        $this->assertSame(4, $totals['writes']);
        $this->assertIsArray($quoted);
        foreach ($expected as $name) {
            $this->assertContains($name, $quoted, $name . ' is a write tool and must be counted');
            $this->assertContains($name, $reader->getWriteTools());
        }
        $this->assertContains('cms.page.delete', $quoted, 'core write tools count too');
        $this->assertNotContains('system.store_info', $quoted);
        $this->assertSame(
            array_values(array_diff($reader->getWriteTools(), $reader->getDestructiveTools())),
            $reader->getDraftWriteTools()
        );
        $this->assertSame([], array_diff($reader->getDestructiveTools(), $reader->getWriteTools()));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function names(): array
    {
        return [
            'publish' => ['hyva_cms.publish', true],
            'form publish' => ['hyva_form.form.publish', true],
            'core delete' => ['cms.block.delete', true],
            'trash' => ['hyva_media.asset.trash', true],
            'trash restore' => ['hyva_media.trash.restore', true],
            'version restore' => ['hyva_cms.version.restore', true],
            'item remove' => ['hyva_menu.item.remove', true],
            'optimisation clear' => ['hyva_media.optimization.clear', true],
            'image replace' => ['hyva_media.image.replace', true],
            'schedule cancel' => ['hyva_cms.schedule.cancel', true],
            'import' => ['hyva_cms.import', true],
            'menu category import' => ['hyva_menu.category.import', true],
            'asset move' => ['hyva_media.asset.move', true],
            'cache flush' => ['system.cache.flush', true],
            'draft create' => ['hyva_cms.draft.create', false],
            'draft update' => ['hyva_cms.draft.update', false],
            'alt text' => ['hyva_media.image.update_alt', false],
            'page create' => ['hyva_cms.page.create', false],
            'menu item add' => ['hyva_menu.item.add', false],
            'folder create' => ['hyva_media.folder.create', false],
            'positions set' => ['hyva_merch.category.positions_set', false],
            'verb only as a prefix of an entity' => ['hyva_media.trash.list', false],
        ];
    }

    /**
     * @dataProvider names
     * @param string $name
     * @param bool $destructive
     * @return void
     */
    public function testDestructiveClassification(string $name, bool $destructive): void
    {
        $reader = $this->reader([$name => true], $this->createMock(AdapterInterface::class));

        $this->assertSame($destructive, $reader->isDestructiveName($name));
        $this->assertSame($destructive, in_array($name, $reader->getDestructiveTools(), true));
    }

    public function testEveryRegisteredDestructiveToolOfThisModuleIsAWriteTool(): void
    {
        $tools = self::moduleTools();
        $reader = $this->reader($tools, $this->createMock(AdapterInterface::class));
        foreach ($tools as $name => $isWrite) {
            if (!$isWrite) {
                $this->assertNotContains($name, $reader->getDestructiveTools(), 'read tools are never destructive');
            }
        }
        foreach (['hyva_cms.publish', 'hyva_media.asset.trash', 'hyva_menu.item.remove'] as $name) {
            $this->assertContains($name, $reader->getDestructiveTools());
        }
    }

    public function testAnEmptyToolFilterMatchesNothingInsteadOfEverything(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $connection->method('select')->willReturn($select);
        $connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->reader([], $connection)->getRows(['tools' => []], 5));
    }

    /**
     * @param array<string, bool> $tools name => is write
     * @param AdapterInterface $connection
     * @return McpActivityReader
     */
    private function reader(array $tools, AdapterInterface $connection): McpActivityReader
    {
        $registered = [];
        foreach ($tools as $name => $isWrite) {
            $tool = $this->createMock(ToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getWriteMode')->willReturn($isWrite ? WriteMode::WRITE : WriteMode::READ);
            $registered[$name] = $tool;
        }
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturn($registered);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);
        return new McpActivityReader($resource, $authorization, $registry);
    }

    /**
     * Tools registered in this module's etc/di.xml, write flag read from each class's IS_WRITE constant.
     *
     * @return array<string, bool>
     */
    private static function moduleTools(): array
    {
        if (self::$moduleTools !== []) {
            return self::$moduleTools;
        }
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml'));
        $items = $xml->xpath(
            '//type[@name="Magebit\\Mcp\\Model\\Tool\\ToolRegistry"]/arguments/argument[@name="tools"]/item'
        );
        foreach ($items ?: [] as $item) {
            /** @var class-string $class */
            $class = trim((string) $item);
            $reflection = new ReflectionClass($class);
            $name = $reflection->getConstant('TOOL_NAME');
            self::assertSame((string) $item['name'], $name, $class . ' is registered under its TOOL_NAME');
            self::$moduleTools[(string) $name] = (bool) $reflection->getConstant('IS_WRITE');
        }
        return self::$moduleTools;
    }
}
