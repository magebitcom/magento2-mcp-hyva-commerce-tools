<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\Description;

use Magebit\Mcp\Api\PromptInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Model\Util\RegisteredEntries;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * Registry identities stay dotted: the core PromptRegistry and ToolRegistry reject (and so break every MCP request)
 * a name that does not match RegisteredEntries::NAME_PATTERN or differs from its di.xml key. Only tool names are
 * converted to underscores on the wire, by the core; descriptions use that wire form, getName() never does.
 */
class RegistryNameTest extends TestCase
{
    public function testEveryPromptKeyEqualsItsDottedName(): void
    {
        $checked = $this->assertRegistry('Magebit\\Mcp\\Model\\Prompt\\PromptRegistry', 'prompts');
        $this->assertSame(3, $checked);
    }

    public function testEveryToolKeyEqualsItsDottedName(): void
    {
        $checked = $this->assertRegistry('Magebit\\Mcp\\Model\\Tool\\ToolRegistry', 'tools');
        $this->assertGreaterThan(100, $checked);
    }

    /**
     * Assert key === getName() and the core name pattern for every item of a registry argument in etc/di.xml.
     *
     * @param string $registry
     * @param string $argument
     * @return int Number of items checked.
     */
    private function assertRegistry(string $registry, string $argument): int
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml'));
        $items = $xml->xpath(sprintf(
            '//type[@name="%s"]/arguments/argument[@name="%s"]/item',
            $registry,
            $argument
        ));
        $checked = 0;
        foreach ($items ?: [] as $item) {
            $key = (string) $item['name'];
            /** @var class-string<PromptInterface|ToolInterface> $class */
            $class = trim((string) $item);
            $entry = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $name = $entry->getName();
            $this->assertMatchesRegularExpression(RegisteredEntries::NAME_PATTERN, $name, $class);
            $this->assertSame($key, $name, $class);
            $checked++;
        }
        return $checked;
    }
}
