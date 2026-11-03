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
 * T-64: every list tool that paginates names `page` and `page_size` (default 50, max 200) in its description and
 * input schema, and offers no `limit` alias.
 */
class ListPaginationTest extends TestCase
{
    public function testEveryPaginatedToolNamesPageAndPageSize(): void
    {
        $checked = [];
        $problems = [];
        foreach ($this->registeredTools() as $tool) {
            try {
                $schema = $tool->getInputSchema();
                $description = $tool->getDescription();
            } catch (\Error) {
                continue; // Needs constructor dependencies; those tools are not paginated lists.
            }
            $properties = $schema['properties'] ?? [];
            if (!is_array($properties) || !isset($properties['page_size'])) {
                continue;
            }
            $checked[] = str_replace('.', '_', $tool->getName());
            $pageSize = $properties['page_size'];
            $page = $properties['page'] ?? [];
            $ok = isset($properties['page'])
                && !isset($properties['limit'])
                && str_contains($description, 'page_size (default 50, max 200)')
                && ($pageSize['maximum'] ?? null) === 200
                && str_contains((string) ($pageSize['description'] ?? ''), 'default 50')
                && str_contains((string) ($pageSize['description'] ?? ''), 'max 200')
                && str_contains((string) ($page['description'] ?? ''), 'default 1');
            if (!$ok) {
                $problems[] = str_replace('.', '_', $tool->getName());
            }
        }
        $this->assertContains('hyva_cms_page_list', $checked);
        $this->assertGreaterThanOrEqual(9, count($checked));
        $this->assertSame([], $problems);
    }

    /**
     * T-83: a list tool without page/page_size says "Not paginated" in its description.
     */
    public function testEveryOtherListToolSaysItIsNotPaginated(): void
    {
        $checked = [];
        $problems = [];
        foreach ($this->registeredTools() as $tool) {
            try {
                $schema = $tool->getInputSchema();
                $description = $tool->getDescription();
            } catch (\Error) {
                continue; // Needs constructor dependencies.
            }
            $name = str_replace('.', '_', $tool->getName());
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            if (!str_ends_with($name, '_list') || isset($properties['page_size'])) {
                continue;
            }
            $checked[] = $name;
            if (!str_contains($description, 'Not paginated')) {
                $problems[] = $name;
            }
        }
        foreach (['hyva_cms_component_list', 'hyva_menu_list', 'hyva_menu_version_list', 'hyva_dashboard_view_list',
            'hyva_dashboard_widget_list', 'hyva_form_version_list'] as $name) {
            $this->assertContains($name, $checked);
        }
        $this->assertSame([], $problems);
    }

    /**
     * Tools registered in etc/di.xml, built without their constructor.
     *
     * @return list<ToolInterface>
     */
    private function registeredTools(): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml'));
        $items = $xml->xpath(
            '//type[@name="Magebit\\Mcp\\Model\\Tool\\ToolRegistry"]/arguments/argument[@name="tools"]/item'
        );
        $tools = [];
        foreach ($items ?: [] as $item) {
            /** @var class-string<ToolInterface> $class */
            $class = trim((string) $item);
            $tools[] = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        }
        return $tools;
    }
}
