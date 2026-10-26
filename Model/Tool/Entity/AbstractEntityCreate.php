<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;

/**
 * Shared implementation of hyva_cms.page.create / hyva_cms.block.create: creates the Magento entity and its first
 * Hyvä draft.
 *
 * Creating an active entity puts it on the storefront at once, so is_active=true needs `confirm: true` (without it
 * nothing is written and a preview comes back). An inactive entity is not served whatever its identifier and store
 * views, and a new one has no links or placements yet, so those need no confirm on create.
 */
abstract class AbstractEntityCreate extends AbstractTool implements ContextualAclAwareInterface
{
    public const ENTITY_TYPE = '';
    public const ENTITY_LABEL = '';
    public const IS_WRITE = true;

    /**
     * @param EntityWriter $entityWriter
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     * @param ContentLocator $contentLocator
     * @param PreviewService $previewService
     * @param LiveSettings $liveSettings
     * @param ContentAcl $contentAcl
     * @param AdminScopeGuard $scopeGuard
     * @param CallerContext $callerContext
     */
    public function __construct(
        private readonly EntityWriter $entityWriter,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor,
        private readonly ContentLocator $contentLocator,
        private readonly PreviewService $previewService,
        private readonly LiveSettings $liveSettings,
        private readonly ContentAcl $contentAcl,
        private readonly AdminScopeGuard $scopeGuard,
        private readonly CallerContext $callerContext
    ) {
    }

    /**
     * View permission on the copy_from source type: copying reads that entity's content.
     *
     * Called before schema validation; a malformed copy_from adds nothing here and is refused by the schema.
     *
     * @inheritDoc
     */
    public function getContextualAclResources(array $arguments): array
    {
        $copyFrom = $arguments['copy_from'] ?? null;
        $sourceType = is_array($copyFrom) ? ($copyFrom['entity_type'] ?? null) : null;
        if (!is_string($sourceType) || !in_array($sourceType, ContentLocator::TYPES, true)) {
            return [];
        }
        return $this->contentAcl->resources([$sourceType], [ContentAcl::VERB_VIEW]);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Create Hyvä CMS ' . static::ENTITY_LABEL;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Create a CMS %1$s with Hyvä CMS content saved as a DRAFT. The %1$s is inactive by default and nothing is '
                . 'visible on the storefront until hyva_cms.publish. is_active=true puts the %1$s live at once and '
                . 'needs "confirm": true; without it nothing is created and the reply shows what would go live. Start '
                . 'from components, or copy the current content of another page/block/menu (copy_from). Returns the '
                . 'id, draft version and preview URLs.',
            strtolower(static::ENTITY_LABEL)
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->string('title', fn (StringBuilder $s) => $s->minLength(1)->maxLength(255)->required())
            ->string('identifier', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(100)
                ->pattern('^[a-zA-Z0-9_\\-\\/\\.]+$')
                ->required()
                ->description('URL key / identifier, unique per store view.'))
            ->array('store_ids', fn (ArrayBuilder $a) => $a
                ->ofIntegers()
                ->description('Store views; default [0] = all.'))
            ->boolean('is_active', fn (BooleanBuilder $b) => $b
                ->description('Default false. The Magento active flag: true makes the '
                    . strtolower(static::ENTITY_LABEL) . ' live at once and needs confirm.'));
        foreach ($this->entityFields() as $field => $description) {
            $schema->string($field, fn (StringBuilder $s) => $s->description($description));
        }
        return $schema
            ->rawProperty('components', ContentSchema::COMPONENTS)
            ->object('copy_from', fn (ObjectBuilder $o) => $o
                ->string('entity_type', fn (StringBuilder $s) => $s->enum(ContentLocator::TYPES)->required())
                ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
                ->description('Start from the working content (draft, else published) of another entity.'))
            ->string('version_name', fn (StringBuilder $s) => $s
                ->maxLength(100)
                ->description('Label for the version-history entry.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Create with is_active=true, after '
                . 'reviewing the preview.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $fields = [];
        foreach (static::ENTITY_TYPE === ContentLocator::TYPE_PAGE
                ? EntityWriter::PAGE_FIELDS
                : EntityWriter::BLOCK_FIELDS as $name) {
            if (array_key_exists($name, $arguments)) {
                $fields[$name] = $arguments[$name];
            }
        }

        $deniedStores = $this->deniedStoreIds($fields);
        if ($deniedStores !== []) {
            return $this->softError(
                'Nothing was created: your admin role may not write to store view(s) '
                    . implode(', ', $deniedStores) . ' (0 = all store views). Pass store_ids within your scope.',
                ['status' => 403, 'denied_store_ids' => $deniedStores]
            );
        }

        $copyFrom = $this->arrayArg($arguments, 'copy_from');
        $document = null;
        if ($copyFrom !== []) {
            $sourceType = is_string($copyFrom['entity_type'] ?? null) ? $copyFrom['entity_type'] : '';
            $source = $this->contentLocator->locate($sourceType, ['id' => $copyFrom['id'] ?? null]);
            $document = $this->contentService->getWorkingDocument($source['type'], $source['id']);
        }
        $components = $this->draftEditor->buildNodes($this->arrayArg($arguments, 'components'));

        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $draft = $this->componentTree->normalizeDocument(['content' => $content], static::ENTITY_TYPE, 0);
        foreach ($components as $node) {
            $draft = $this->componentTree->insertNode($draft, $node);
        }
        $validation = $this->contentService->validate($draft);
        if (!$validation['valid']) {
            return $this->softError('Nothing was created: the content is invalid.', $validation);
        }

        $live = $this->liveSettings->changes(['is_active' => false], ['is_active' => !empty($fields['is_active'])]);
        if ($live !== [] && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('create_active', [[
                'entity_type' => static::ENTITY_TYPE,
                'identifier' => $fields['identifier'] ?? null,
                'title' => $fields['title'] ?? null,
                'store_ids' => $fields['store_ids'] ?? [0],
                'components' => count($components),
                'changes' => $live,
            ]]);
        }

        $id = $this->entityWriter->create(static::ENTITY_TYPE, $fields);
        $document = $this->componentTree->normalizeDocument($draft, static::ENTITY_TYPE, $id);
        $saved = $this->contentService->saveDraft(
            static::ENTITY_TYPE,
            $id,
            $document,
            $this->stringArg($arguments, 'version_name')
        );

        return $this->jsonResult([
            'created' => true,
            'entity_type' => static::ENTITY_TYPE,
            'id' => $id,
            'identifier' => $fields['identifier'] ?? null,
            'draft' => $saved,
            'published' => false,
            'preview' => $this->previewService->getPreview(static::ENTITY_TYPE, $id),
            'next_step' => 'Review the preview, then call hyva_cms.publish to make the content live'
                . (empty($fields['is_active']) ? ' (and set is_active=true with the update tool).' : '.'),
        ], ['entity_type' => static::ENTITY_TYPE, 'id' => $id, 'components' => count($components)]);
    }

    /**
     * Requested store views the caller's Advanced Admin Permissions scope may not write to.
     *
     * The default (all store views, 0) is refused for a restricted role: content saved there shows in every store
     * view, including those outside the role's scope.
     *
     * @param array $fields
     * @return array
     * @phpstan-param array<string, mixed> $fields
     * @phpstan-return list<int>
     */
    private function deniedStoreIds(array $fields): array
    {
        $scopes = $this->scopeGuard->allowedScopes($this->callerContext->adminUserId());
        if (!$scopes['restricted']) {
            return [];
        }
        $requested = $fields['store_ids'] ?? null;
        $ids = is_array($requested) ? $requested : [$requested ?? 0];
        $ids = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
        return array_values(array_unique(array_diff($ids === [] ? [0] : $ids, $scopes['store_ids'])));
    }

    /**
     * Extra Magento string fields of this entity type (name => description).
     *
     * @return array<string, string>
     */
    protected function entityFields(): array
    {
        return [];
    }
}
