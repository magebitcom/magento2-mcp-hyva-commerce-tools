<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit;

use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.audit_all` MCP tool.
 */
class AuditAll extends AbstractMediaTool
{
    public const TOOL_NAME = 'hyva_media.audit_all';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_audit_all';

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
        return 'Audit Storefront Media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Audit every image referenced in storefront content (Hyvä CMS, menus, Magento CMS, product/category '
            . 'descriptions): oversized files and dimensions, missing alt text, broken references, unsupported '
            . 'formats, large PNGs, heavy hero/banner images, missing optimized variants, plus Media Optimization '
            . 'settings (disabled optimization, automatic replacement off).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('max_kb', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Oversize threshold; default 500.'))
            ->integer('limit', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(1000)
                ->description('Images listed; default 100.'))
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
            $this->intArg($arguments, 'limit', 100) ?? 100
        );
        return $this->jsonResult($report, ['images_checked' => $report['images_checked']] + $report['counts']);
    }
}
