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
 * `hyva_media.audit_cms` MCP tool.
 */
class AuditCms extends AbstractMediaTool
{
    public const TOOL_NAME = 'hyva_media.audit_cms';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_audit_cms';

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
        return 'Audit Hyvä CMS Media';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Audit only images used in Hyvä CMS pages and blocks (draft and published): missing alt text, large '
            . 'hero images, unoptimized campaign images, broken references. Pages and blocks outside your role\'s '
            . 'websites/store views are not checked (usages_hidden_by_permissions).';
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
            'cms',
            $this->intArg($arguments, 'max_kb', 500) ?? 500,
            $this->intArg($arguments, 'limit', 100) ?? 100
        );
        return $this->jsonResult($report, ['images_checked' => $report['images_checked']] + $report['counts']);
    }
}
