<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Transfer;

use Hyva\CmsImportExport\Model\Import\ContentProcessor;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\Exception\LocalizedException;

/**
 * `hyva_cms.import`: import Hyvä CMS JSON as a DRAFT into an existing or new page/block. Never publishes.
 */
class TransferImport extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.import';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_import';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SAVE];
    public const EXTRA_ACL_RESOURCES = [ContentAcl::TRANSFER_RESOURCE];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ContentIndex $contentIndex
     * @param ComponentTree $componentTree
     * @param ContentProcessor $importProcessor
     * @param EntityWriter $entityWriter
     * @param ImageReferenceExtractor $imageReferenceExtractor
     * @param MediaFileInfo $mediaFileInfo
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ContentIndex $contentIndex,
        private readonly ComponentTree $componentTree,
        private readonly ContentProcessor $importProcessor,
        private readonly EntityWriter $entityWriter,
        private readonly ImageReferenceExtractor $imageReferenceExtractor,
        private readonly MediaFileInfo $mediaFileInfo,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Import Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Import Hyvä CMS content (the hyva_cms.export payload, or a bare content document) as a DRAFT into an '
            . 'existing page/block/menu (entity_type + id/identifier) or a new page/block (create_new: true, uses '
            . 'payload.entity for title/identifier/stores). Content is validated first and references (categories, '
            . 'products, links) are resolved for this store. dry_run validates only. Never publishes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->rawProperty(
                'payload',
                ['type' => 'object', 'description' => 'hyva_cms.export output, or a document with "content".'],
                true
            )
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->string('edition', fn (StringBuilder $s) => $s
                ->enum(['draft', 'published'])
                ->description('Which payload content to import; default draft (falls back to published).'))
            ->boolean('create_new', fn (BooleanBuilder $b) => $b
                ->description('Create a new page/block from payload.entity instead of importing into an existing one.'))
            ->boolean('dry_run', fn (BooleanBuilder $b) => $b->description('Validate and report without saving.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b
                ->description('Required when the target has unpublished draft changes.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->requireString($arguments, 'entity_type');
        $payload = $this->arrayArg($arguments, 'payload');
        $edition = $this->stringArg($arguments, 'edition', 'draft');
        $source = Cast::array($payload[$edition === 'published' ? 'published_content' : 'draft_content'] ?? null);
        if ($source === [] && $edition !== 'published') {
            $source = Cast::array($payload['published_content'] ?? null);
        }
        if ($source === [] && isset($payload['content'])) {
            $source = $payload;
        }
        if (!is_array($source['content'] ?? null)) {
            return $this->softError('The payload has no Hyvä CMS content to import.');
        }

        $processed = $this->importProcessor->processForImport($source);
        $warnings = array_map(
            static fn ($w): string => is_scalar($w) ? (string) $w : (string) json_encode($w),
            $this->importProcessor->getWarnings()
        );
        $document = $this->componentTree->normalizeDocument(
            ['content' => Cast::array($processed['content'] ?? null)],
            $type,
            0
        );
        foreach ($this->imageReferenceExtractor->fromDocument($document) as $image) {
            if (!$this->mediaFileInfo->exists($image['path'])) {
                $warnings[] = sprintf('Image "%s" is missing from pub/media.', $image['path']);
            }
        }
        $validation = $this->contentService->validate($document);
        $report = [
            'valid' => $validation['valid'],
            'validation' => $validation,
            'warnings' => array_values(array_unique($warnings)),
            'components' => $this->componentTree->countComponents($document),
        ];
        if (!$validation['valid'] || $this->boolArg($arguments, 'dry_run')) {
            return $this->jsonResult(
                ['dry_run' => true, 'saved' => false] + $report,
                ['entity_type' => $type, 'valid' => $validation['valid']],
                !$validation['valid']
            );
        }

        if ($this->boolArg($arguments, 'create_new')) {
            if ($type === ContentLocator::TYPE_MENU) {
                return $this->softError('Create the menu with hyva_menu.create first, then import into it.');
            }
            $entityId = $this->entityWriter->create(
                $type,
                $this->entityFields(Cast::array($payload['entity'] ?? null))
            );
        } else {
            $entityId = $this->contentLocator->locate($type, $arguments)['id'];
            $current = $this->contentService->getDocuments($type, $entityId);
            $dirty = $current['draft'] !== null && !$this->contentIndex->sameContent(
                (string) json_encode($current['draft']),
                $current['published'] !== null ? (string) json_encode($current['published']) : null
            );
            if ($dirty && !$this->boolArg($arguments, 'confirm')) {
                return $this->confirmationRequired(
                    'replace_draft_with_import',
                    [['entity_type' => $type, 'id' => $entityId]]
                );
            }
        }

        $saved = $this->contentService->saveDraft(
            $type,
            $entityId,
            $this->componentTree->normalizeDocument($document, $type, $entityId),
            'Imported (MCP)'
        );
        return $this->jsonResult([
            'saved' => true,
            'entity_type' => $type,
            'id' => $entityId,
            'draft' => $saved,
            'published' => false,
        ] + $report, [
            'entity_type' => $type,
            'id' => $entityId,
            'warnings' => count($report['warnings']),
        ]);
    }

    /**
     * Entity fields.
     *
     * @param array $entity
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<mixed> $entity
     * @phpstan-return array<string, mixed>
     */
    private function entityFields(array $entity): array
    {
        $identifier = Cast::string($entity['identifier'] ?? '');
        $title = Cast::string($entity['title'] ?? '');
        if ($identifier === '' || $title === '') {
            throw new LocalizedException(__('payload.entity needs identifier and title to create a new entity.'));
        }
        $fields = [
            'identifier' => $identifier,
            'title' => $title,
            'is_active' => false,
            'store_ids' => Cast::intList($entity['stores'] ?? [0]) ?: [0],
        ];
        foreach (['page_layout', 'content_heading', 'meta_title', 'meta_keywords', 'meta_description'] as $optional) {
            if (isset($entity[$optional]) && is_scalar($entity[$optional])) {
                $fields[$optional] = (string) $entity[$optional];
            }
        }
        return $fields;
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
