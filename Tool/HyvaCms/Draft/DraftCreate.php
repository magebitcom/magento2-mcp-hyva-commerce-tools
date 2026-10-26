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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.draft.create`: (re)start an entity's draft from the published content, a version or given components.
 */
class DraftCreate extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.draft.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_draft_create';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SAVE];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ContentIndex $contentIndex
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ContentIndex $contentIndex,
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
        return 'Create Hyvä CMS Draft';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Replace the draft of a page, block or menu with: the published content (source=published), a '
            . 'version-history item (source=version + version_id), or the given components (source=components). If '
            . 'the current draft has unpublished changes, they are kept in version history and "confirm": true is '
            . 'required. Never publishes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->string('source', fn (StringBuilder $s) => $s->enum(['published', 'version', 'components'])->required())
            ->integer('version_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Required for source=version.'))
            ->rawProperty('components', ContentSchema::COMPONENTS)
            ->string('version_name', fn (StringBuilder $s) => $s->maxLength(100))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b
                ->description('Required when unpublished draft changes would be replaced.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->requireString($arguments, 'entity_type');
        $entity = $this->contentLocator->locate($type, $arguments);
        $source = $this->requireString($arguments, 'source');
        $documents = $this->contentService->getDocuments($entity['type'], $entity['id']);

        if ($source === 'published') {
            if ($documents['published'] === null) {
                return $this->softError('There is no published content to start a draft from.');
            }
            $document = $documents['published'];
        } elseif ($source === 'version') {
            $versionId = $this->requireInt($arguments, 'version_id');
            $document = $this->componentTree->parse(
                $this->contentService->getVersionData($entity['type'], $entity['id'], $versionId),
                $entity['type'],
                $entity['id']
            );
        } else {
            $document = $this->componentTree->newDocument($entity['type'], $entity['id']);
            foreach ($this->draftEditor->buildNodes($this->arrayArg($arguments, 'components')) as $node) {
                $document = $this->componentTree->insertNode($document, $node);
            }
        }

        $draft = $documents['draft'];
        $unpublishedChanges = $draft !== null && !$this->contentIndex->sameContent(
            (string) json_encode($draft),
            $documents['published'] !== null ? (string) json_encode($documents['published']) : null
        );
        if ($unpublishedChanges && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('replace_draft', [[
                'entity_type' => $entity['type'],
                'id' => $entity['id'],
                'current_draft_components' => $this->componentTree->countComponents($draft),
                'new_draft_components' => $this->componentTree->countComponents($document),
            ]]);
        }

        $saved = $this->contentService->saveDraft(
            $entity['type'],
            $entity['id'],
            $document,
            $this->stringArg($arguments, 'version_name')
        );
        return $this->jsonResult(
            [
                'entity_type' => $entity['type'],
                'id' => $entity['id'],
                'source' => $source,
                'draft' => $saved,
                'published' => false,
            ],
            ['entity_type' => $entity['type'], 'id' => $entity['id'], 'source' => $source]
        );
    }

    /**
     * Save; source=version also reads version history, like hyva_cms.version.restore.
     *
     * @inheritDoc
     */
    protected function contentVerbs(array $arguments): array
    {
        $verbs = parent::contentVerbs($arguments);
        if ($this->stringArg($arguments, 'source') === 'version') {
            $verbs[] = ContentAcl::VERB_VERSION_HISTORY;
        }
        return $verbs;
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
