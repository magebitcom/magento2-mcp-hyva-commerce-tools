<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Migration;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.migration.analyze` MCP tool.
 */
class MigrationAnalyze extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.migration.analyze';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_migration_analyze';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * @param ContentLocator $contentLocator
     * @param MigrationService $migrationService
     * @param ComponentTree $componentTree
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly MigrationService $migrationService,
        private readonly ComponentTree $componentTree,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Analyze Legacy CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Analyse one Page Builder/WYSIWYG page or block: sections, columns, images (and missing files), '
            . 'buttons, sliders, banners, tabs, which Hyvä CMS component each element becomes in conversion, the '
            . 'better-fit component for HTML fallbacks, complexity, and a preview outline of the converted draft. '
            . 'Saves nothing.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK]))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $converted = $this->migrationService->convert($entity['type'], $entity['id']);
        $outline = [];
        foreach ($this->componentTree->flatten($converted['document']) as $row) {
            $outline[] = ['component' => $row['node']['component'] ?? null, 'depth' => $row['depth']];
        }
        return $this->jsonResult([
            'entity' => $entity,
            'analysis' => $converted['analysis'],
            'conversion_preview' => [
                'components' => $this->componentTree->countComponents($converted['document']),
                'outline' => $outline,
                'warnings' => $converted['warnings'],
            ],
        ], [
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'complexity' => $converted['analysis']['complexity'],
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
