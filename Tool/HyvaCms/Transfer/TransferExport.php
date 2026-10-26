<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Transfer;

use Hyva\CmsImportExport\Model\ContentType\HandlerPool;
use Hyva\CmsImportExport\Model\Export\ContentProcessor;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.export`: export a page/block/menu in the Hyvä CMS Import/Export format.
 */
class TransferExport extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.export';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_export';
    public const CONTENT_VERBS = [ContentAcl::VERB_EXPORT];
    public const EXTRA_ACL_RESOURCES = [ContentAcl::TRANSFER_RESOURCE];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param HandlerPool $handlerPool
     * @param ContentProcessor $exportProcessor
     * @param ImageReferenceExtractor $imageReferenceExtractor
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly HandlerPool $handlerPool,
        private readonly ContentProcessor $exportProcessor,
        private readonly ImageReferenceExtractor $imageReferenceExtractor,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Export Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Export the Hyvä CMS content of a page, block or menu (draft and/or published) as JSON in the Hyvä '
            . 'Import/Export format, with entity settings, link/category/product reference hints for another '
            . 'environment and the media paths it needs. Feed the payload to hyva_cms.import.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->string('edition', fn (StringBuilder $s) => $s
                ->enum(['draft', 'published', 'both'])
                ->description('Default both.'))
            ->boolean('include_versions', fn (BooleanBuilder $b) => $b->description('Add the version-history summary.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $edition = $this->stringArg($arguments, 'edition', 'both');
        $documents = $this->contentService->getDocuments($entity['type'], $entity['id']);

        $entityData = [
            'identifier' => $entity['identifier'],
            'title' => $entity['title'],
            'is_active' => $entity['is_active'],
            'stores' => $entity['store_ids'],
        ];
        if ($this->handlerPool->has($entity['type'])) {
            $entityData = $this->handlerPool->get($entity['type'])->getEntityData($entity['id']);
        }

        $payload = [
            'version' => '1.0',
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'entity_type' => $entity['type'],
            'entity' => $entityData,
        ];
        $images = [];
        foreach (['draft' => 'draft_content', 'published' => 'published_content'] as $key => $target) {
            if ($edition !== 'both' && $edition !== $key) {
                continue;
            }
            $document = $documents[$key];
            $payload[$target] = $document !== null ? $this->exportProcessor->processForExport($document) : null;
            if ($document !== null) {
                $images[] = array_column($this->imageReferenceExtractor->fromDocument($document), 'path');
            }
        }
        $payload['image_paths'] = array_values(array_unique(array_merge([], ...$images)));
        if ($this->boolArg($arguments, 'include_versions')) {
            $payload['version_history'] = $this->contentService->getVersions($entity['type'], $entity['id']);
        }

        return $this->jsonResult(
            $payload,
            ['entity_type' => $entity['type'], 'id' => $entity['id'], 'edition' => $edition]
        );
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
