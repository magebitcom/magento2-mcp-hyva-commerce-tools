<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Component;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentCatalog;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

/**
 * `hyva_cms.component.schema` MCP tool.
 */
class ComponentSchema extends AbstractTool
{
    public const TOOL_NAME = 'hyva_cms.component.schema';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_component_schema';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::cms';

    /**
     * @param ComponentCatalog $componentCatalog
     */
    public function __construct(
        private readonly ComponentCatalog $componentCatalog
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Hyvä CMS Component Schema';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Field schema of one component: every field with group, type, label, required flag, default value, '
            . 'allowed options, validation and value_format (the exact JSON shape the editor stores for that field '
            . 'type, with an example: e.g. link = {type, value, label, open_in_new_tab, prefix, suffix}), the '
            . 'children rules and an example node with defaults. Use it to generate valid component JSON.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('component', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->required()
                ->description('Component name, e.g. "heading".'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $name = $this->requireString($arguments, 'component');
        return $this->jsonResult($this->componentCatalog->getSchema($name), ['component' => $name]);
    }
}
