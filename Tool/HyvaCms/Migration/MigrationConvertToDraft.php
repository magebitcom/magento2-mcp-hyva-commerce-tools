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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.migration.convert_to_draft`: Page Builder/WYSIWYG → Hyvä CMS draft; the Magento content is kept.
 */
class MigrationConvertToDraft extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.migration.convert_to_draft';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_migration_convert_to_draft';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SAVE];

    /**
     * @param ContentLocator $contentLocator
     * @param MigrationService $migrationService
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param PreviewService $previewService
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly MigrationService $migrationService,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly PreviewService $previewService,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Convert Legacy CMS Content to Draft';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Convert a Page Builder/WYSIWYG page or block into a Hyvä CMS DRAFT with Hyvä\'s own migrator '
            . '(mode=full maps rows/columns/headings/text/images to components, everything else becomes HTML '
            . 'components; mode=partial keeps every top-level element as HTML). The original Magento content is not '
            . 'changed and the storefront keeps showing it until hyva_cms.publish. Replacing an existing Hyvä draft '
            . 'needs "confirm": true.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK]))
            ->string('mode', fn (StringBuilder $s) => $s->enum(['full', 'partial'])->description('Default full.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b
                ->description('Required when the entity already has a Hyvä draft.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locateForWrite($this->requireString($arguments, 'entity_type'), $arguments);
        $existing = $this->contentService->getDocuments($entity['type'], $entity['id']);
        if ($existing['draft'] !== null && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('replace_hyva_draft_with_migration', [[
                'entity_type' => $entity['type'],
                'id' => $entity['id'],
                'current_draft_components' => $this->componentTree->countComponents($existing['draft']),
            ]]);
        }

        $converted = $this->migrationService->convert(
            $entity['type'],
            $entity['id'],
            $this->stringArg($arguments, 'mode', 'full') ?? 'full'
        );
        if ($this->componentTree->getRootNodes($converted['document']) === []) {
            return $this->softError('The Magento content is empty; nothing to convert.');
        }
        $saved = $this->contentService->saveDraft(
            $entity['type'],
            $entity['id'],
            $converted['document'],
            'Migrated from Page Builder (MCP)'
        );

        return $this->jsonResult([
            'converted' => true,
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'draft' => $saved,
            'components' => $this->componentTree->countComponents($converted['document']),
            'warnings' => $converted['warnings'],
            'original_content_kept' => true,
            'published' => false,
            'preview' => $this->previewService->getPreview($entity['type'], $entity['id']),
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'warnings' => count($converted['warnings'])]);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
