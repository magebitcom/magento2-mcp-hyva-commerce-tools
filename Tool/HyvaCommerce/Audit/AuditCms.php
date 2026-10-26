<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Audit\CmsAuditor;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_commerce.audit.cms` MCP tool.
 */
class AuditCms extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_commerce.audit.cms';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_commerce_audit_cms';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * @param CmsAuditor $cmsAuditor
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly CmsAuditor $cmsAuditor,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Hyvä CMS health report. counts.<cms_page|cms_block> has total and three groups. status (published, '
            . 'published_with_draft, draft_only, not_hyva - the same status page.list and block.list report per row) '
            . 'and rendered_by (hyva_cms = Liveview editor enabled, magento = storefront shows the Magento content) '
            . 'each add up to total. legacy is the subset rendered by Magento with Magento content left (= the '
            . 'migration.scan items): page_builder + wysiwyg + empty add up to legacy.total, and '
            . 'converted_drafts_waiting is part of it. Also scheduled releases and conflicts, and broken content '
            . '(invalid JSON, unknown/disabled components, validation errors, missing alt text, missing images).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('issue_limit', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->maximum(1000)
                ->description('Max issues listed; default 100.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $report = $this->cmsAuditor->audit($this->intArg($arguments, 'issue_limit', 100) ?? 100);
        return $this->jsonResult($report, $report['content_issues'] + $report['scheduling']);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }

    /**
     * @inheritDoc
     */
    protected function targetContentTypes(array $arguments): array
    {
        return [ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK];
    }
}
