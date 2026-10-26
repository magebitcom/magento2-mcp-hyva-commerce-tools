<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;

/**
 * Shared implementation of hyva_cms.page.create / hyva_cms.block.create: creates the Magento entity and its first
 * Hyvä draft.
 */
abstract class AbstractEntityCreate extends AbstractTool
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
     */
    public function __construct(
        private readonly EntityWriter $entityWriter,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor,
        private readonly ContentLocator $contentLocator,
        private readonly PreviewService $previewService
    ) {
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
                . 'visible on the storefront until hyva_cms.publish. Start from components, or copy the current '
                . 'content of another page/block (copy_from). Returns the id, draft version and preview URLs.',
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
                ->description('Default false. The Magento active flag; Hyvä content still needs publishing.'));
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
     * Extra Magento string fields of this entity type (name => description).
     *
     * @return array<string, string>
     */
    protected function entityFields(): array
    {
        return [];
    }
}
