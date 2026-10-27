<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.audit.literal_urls`.
 */
class AuditLiteralUrls extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.audit.literal_urls';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_audit_literal_urls';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Literal Media URLs';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find images in Hyvä CMS pages, blocks and menus that point at a file path instead of a Media Gallery '
            . 'asset '
            . 'id (they break when the file is moved or renamed): literal_fixable (re-saving the draft stores the id), '
            . 'literal_not_in_gallery (file not indexed), stale_asset_id (asset gone) and literal images inside rich '
            . 'text. id_backed counts the healthy ones. Content outside your role\'s websites/store views is not '
            . 'scanned (documents_hidden_by_permissions).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(1000))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $report = $this->auditor->audit(ContentLocator::TYPES, $this->intArg($arguments, 'limit', 200) ?? 200);
        return $this->jsonResult($report, $report['counts']);
    }

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaGalleryGatewayInterface $gallery
     * @param ImageReferenceExtractor $extractor
     * @param ImageUsageIndex $usageIndex
     * @param CallerContext $callerContext
     * @param LiteralUrlAuditor $auditor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaGalleryGatewayInterface $gallery,
        ImageReferenceExtractor $extractor,
        ImageUsageIndex $usageIndex,
        CallerContext $callerContext,
        private readonly LiteralUrlAuditor $auditor
    ) {
        parent::__construct($featureChecker, $gallery, $extractor, $usageIndex, $callerContext);
    }
}
