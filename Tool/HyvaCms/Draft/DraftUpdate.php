<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Draft;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.draft.update`: targeted edits on a draft (add / remove / move / set_field / replace_node / replace_text).
 */
class DraftUpdate extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.draft.update';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_draft_update';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SAVE];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Update Hyvä CMS Draft';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Apply edits to the draft of a page, block or menu (starts from the published content when there is no '
            . 'draft). Operations run in order and are all-or-nothing; the result is validated before saving. Use '
            . 'dry_run to see the outline and validation without saving. Never publishes. Get uids from '
            . 'hyva_cms.page.get (outline).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $operations = ContentSchema::OPERATIONS;
        $operations['minItems'] = 1;
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->rawProperty('operations', $operations, true)
            ->string('version_name', fn (StringBuilder $s) => $s->maxLength(100))
            ->boolean('dry_run', fn (BooleanBuilder $b) => $b
                ->description('Validate and return the result without saving.'))
            ->string('base_version_uid', fn (StringBuilder $s) => $s->description(
                'Optional: draft version uid you read (draft.version.uid, or version_uid of your last write). The '
                . 'save is refused (status 409) when the draft changed since you read it.'
            ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->requireString($arguments, 'entity_type');
        $entity = $this->contentLocator->locate($type, $arguments);
        $document = $this->contentService->getWorkingDocument($entity['type'], $entity['id']);
        $applied = $this->draftEditor->apply($document, $this->arrayArg($arguments, 'operations'));

        if ($this->boolArg($arguments, 'dry_run')) {
            return $this->jsonResult([
                'dry_run' => true,
                'applied_operations' => $applied['applied'],
                'validation' => $this->contentService->validate($applied['document']),
                'components' => $this->componentTree->countComponents($applied['document']),
            ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'dry_run' => true]);
        }

        $saved = $this->contentService->saveDraft(
            $entity['type'],
            $entity['id'],
            $applied['document'],
            $this->stringArg($arguments, 'version_name'),
            $this->stringArg($arguments, 'base_version_uid')
        );
        return $this->jsonResult([
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'applied_operations' => $applied['applied'],
            'draft' => $saved,
            'published' => false,
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'operations' => count($applied['applied'])]);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
