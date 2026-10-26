<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.publish`: the only tool that makes Hyvä CMS content live.
 */
class Publish extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.publish';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_publish';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_PUBLISH];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Publish Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Make content LIVE on the storefront: publish the current draft of a page, block or menu, or a '
            . 'specific version_id from version history. Also enables Hyvä CMS rendering for the entity and cleans '
            . 'its cache. The previous live version stays in version history.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->integer('version_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Publish this version instead of the current draft.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->requireString($arguments, 'entity_type');
        $entity = $this->contentLocator->locateForWrite($type, $arguments);
        $before = $this->contentService->getDocuments($entity['type'], $entity['id'])['published'];
        $result = $this->contentService->publish(
            $entity['type'],
            $entity['id'],
            $this->intArg($arguments, 'version_id')
        );
        $after = $this->contentService->getDocuments($entity['type'], $entity['id'])['published'];

        return $this->jsonResult([
            'published' => true,
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'identifier' => $entity['identifier'],
            'is_active' => $entity['is_active'],
            'result' => $result,
            'components_before' => $before !== null ? $this->componentTree->countComponents($before) : [],
            'components_after' => $after !== null ? $this->componentTree->countComponents($after) : [],
            'note' => $entity['is_active']
                ? null
                : 'The Magento entity is inactive, so the content is not visible yet.',
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'source' => $result['source']]);
    }

    /**
     * Publish; publishing a version_id also reads version history.
     *
     * @inheritDoc
     */
    protected function contentVerbs(array $arguments): array
    {
        $verbs = parent::contentVerbs($arguments);
        if (($arguments['version_id'] ?? null) !== null) {
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
