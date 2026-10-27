<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.schedule.detect_conflicts` MCP tool.
 */
class ScheduleDetectConflicts extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.schedule.detect_conflicts';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_schedule_detect_conflicts';
    public const CONTENT_VERBS = [ContentAcl::VERB_SCHEDULING];

    /**
     * @param ConflictReport $conflictReport
     * @param ContentLocator $contentLocator
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ConflictReport $conflictReport,
        private readonly ContentLocator $contentLocator,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Detect Hyvä CMS Schedule Conflicts';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check pending scheduled releases for problems: the same page/block/menu in two releases (overlapping '
            . '= within window_minutes, otherwise superseded), different entities with the same identifier on '
            . 'overlapping store views (e.g. two homepages), overdue releases the cron has not published, deleted '
            . 'content, empty releases, and campaigns with no later release to restore the previous content '
            . '(no_fallback).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES, false))
            ->integer('window_minutes', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->maximum(10080)
                ->description('Default 60.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->stringArg($arguments, 'entity_type');
        $entityId = null;
        if ($type !== null && (isset($arguments['id']) || isset($arguments['identifier']))) {
            $entityId = $this->contentLocator->locate($type, $arguments)['id'];
        }
        // Conflicts of content types the role may not schedule, or naming content outside its website/store view
        // restriction, are hidden; release-level conflicts are judged by the release's items.
        $scoped = $this->conflictReport->getConflictsWithinCallerScope(
            $this->intArg($arguments, 'window_minutes', 60) ?? 60,
            $type,
            $entityId,
            $this->contentAcl->allowedTypes(ContentLocator::TYPES, ContentAcl::VERB_SCHEDULING)
        );
        $conflicts = $scoped['conflicts'];
        $hidden = $scoped['hidden'];
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($conflicts as $conflict) {
            $counts[$conflict['severity']]++;
        }
        return $this->jsonResult(
            ['counts' => $counts, 'conflicts' => $conflicts, 'conflicts_hidden_by_permissions' => $hidden],
            $counts
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
