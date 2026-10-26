<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Version;

use Hyva\CmsLiveviewEditor\Model\VersionHistory\VersionHistoryManager;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.version.restore`: restore a version as a NEW draft (Hyvä's own restore flow).
 */
class VersionRestore extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.version.restore';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_version_restore';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SAVE, ContentAcl::VERB_VERSION_HISTORY];

    /**
     * @param ContentLocator $contentLocator
     * @param VersionHistoryManager $versionHistoryManager
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly VersionHistoryManager $versionHistoryManager,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Restore Hyvä CMS Version';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Restore a version-history item (any version: published, pinned or draft) as a new DRAFT, the same way '
            . 'the Liveview editor does. The live content is unchanged until hyva_cms.publish.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->integer('version_id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $versionId = $this->requireInt($arguments, 'version_id');
        $result = $this->versionHistoryManager->restoreVersion($entity['type'], $entity['id'], $versionId);
        if (empty($result['success'])) {
            return $this->softError(is_string($result['error'] ?? null) ? $result['error'] : 'Restore failed.');
        }
        $history = $result['history']['data'] ?? [];
        return $this->jsonResult([
            'restored' => true,
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'restored_version_id' => $versionId,
            'published' => false,
            'history_entries' => is_array($history) ? count($history) : null,
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'version_id' => $versionId]);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
