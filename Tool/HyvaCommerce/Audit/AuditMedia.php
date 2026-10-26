<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit;

use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_commerce.audit.media` MCP tool.
 */
class AuditMedia extends AbstractTool
{
    public const TOOL_NAME = 'hyva_commerce.audit.media';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_commerce_audit_media';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * @param MediaAuditor $auditor
     */
    public function __construct(
        private readonly MediaAuditor $auditor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Hyvä Commerce Media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Media health across storefront content: large images, missing alt text, broken references, '
            . 'unsupported formats, missing optimized variants and Media Optimization configuration gaps (same '
            . 'checks as hyva_media.audit_all, summarised).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('max_kb', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(1000))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $report = $this->auditor->audit(
            'all',
            $this->intArg($arguments, 'max_kb', 500) ?? 500,
            $this->intArg($arguments, 'limit', 50) ?? 50
        );
        return $this->jsonResult($report, ['images_checked' => $report['images_checked']] + $report['counts']);
    }
}
