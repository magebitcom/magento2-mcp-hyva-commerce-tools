<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Version;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.version.list`: version history of a page, block or menu.
 */
class VersionList extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.version.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_version_list';
    public const CONTENT_VERBS = [ContentAcl::VERB_VERSION_HISTORY];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä CMS Versions';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List version-history items (newest first) with state (draft/published), author, date, pinned flag, '
            . 'name and whether it was published by a schedule or restored from another version.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200)->description('Default 50.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $versions = $this->contentService->getVersions($entity['type'], $entity['id']);
        $published = array_values(array_filter($versions, static fn (array $v): bool => $v['was_published']));
        return $this->jsonResult([
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'total' => count($versions),
            'latest_published_version_id' => $published[0]['version_id'] ?? null,
            'versions' => array_slice($versions, 0, $this->intArg($arguments, 'limit', 50) ?? 50),
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'total' => count($versions)]);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
