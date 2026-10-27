<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.preview`: signed storefront preview URLs for the draft or a version.
 */
class Preview extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.preview';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_preview';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * @param ContentLocator $contentLocator
     * @param PreviewService $previewService
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly PreviewService $previewService,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Preview Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Return storefront preview URLs per store view (the current draft, or a version_id) that work without '
            . 'an admin login, the live URL and the admin Liveview editor URL.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->integer('version_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $preview = $this->previewService->getPreview(
            $entity['type'],
            $entity['id'],
            $this->intArg($arguments, 'store_id'),
            $this->intArg($arguments, 'version_id')
        );
        return $this->jsonResult(
            ['entity_type' => $entity['type'], 'id' => $entity['id']] + $preview,
            ['entity_type' => $entity['type'], 'id' => $entity['id']]
        );
    }

    /**
     * View; a version_id preview also reads version history.
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
