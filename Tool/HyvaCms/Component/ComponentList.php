<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Component;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentCatalog;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

/**
 * `hyva_cms.component.list` MCP tool.
 */
class ComponentList extends AbstractTool
{
    public const TOOL_NAME = 'hyva_cms.component.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_component_list';
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
        return 'List Hyvä CMS Components';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List enabled Hyvä CMS components grouped by category, with label, description, providing module, '
            . 'allowed children and whether the component must sit inside a parent.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('category', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('e.g. Elements, Media, Layout.'))
            ->string('search', fn (StringBuilder $s) => $s->minLength(1))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $grouped = [];
        $summaries = $this->componentCatalog->getSummaries(
            $this->stringArg($arguments, 'category'),
            $this->stringArg($arguments, 'search')
        );
        foreach ($summaries as $summary) {
            $grouped[Cast::string($summary['category'])][] = $summary;
        }
        ksort($grouped);
        return $this->jsonResult(
            ['total' => count($summaries), 'categories' => $grouped],
            ['total' => count($summaries)]
        );
    }
}
