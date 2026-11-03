<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\Description;

use Magebit\Mcp\Api\ToolInterface;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * T-9: tool descriptions name other tools by their wire name (hyva_cms_publish), never the dotted one.
 */
class WireNameTest extends TestCase
{
    private const DOTTED = '/\bhyva_[a-z_]+(?:\.[a-z_*]+)+/';

    public function testNoDescriptionNamesAToolWithDots(): void
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml'));
        $items = $xml->xpath(
            '//type[@name="Magebit\\Mcp\\Model\\Tool\\ToolRegistry"]/arguments/argument[@name="tools"]/item'
        );
        $checked = 0;
        $dotted = [];
        foreach ($items ?: [] as $item) {
            /** @var class-string<ToolInterface> $class */
            $class = trim((string) $item);
            $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            try {
                $text = $tool->getDescription();
            } catch (\Error) {
                continue; // Needs constructor dependencies (e.g. field resolvers); covered by its own tests.
            }
            $checked++;
            if (preg_match_all(self::DOTTED, $text, $matches) > 0) {
                $dotted[$tool->getName()] = $matches[0];
            }
        }
        $this->assertGreaterThan(100, $checked);
        $this->assertSame([], $dotted);
    }
}
