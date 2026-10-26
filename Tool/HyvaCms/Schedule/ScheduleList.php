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
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.schedule.list`: scheduled Hyvä CMS releases with their items.
 */
class ScheduleList extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.schedule.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_schedule_list';
    public const CONTENT_VERBS = [ContentAcl::VERB_SCHEDULING];
    public const HISTORY_ROW_CAP = 1000;

    /**
     * @param ScheduleService $scheduleService
     * @param ScheduleTime $scheduleTime
     * @param ContentLocator $contentLocator
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ScheduleTime $scheduleTime,
        private readonly ContentLocator $contentLocator,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä CMS Schedules';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List scheduled content releases (pending by default) with their pages/blocks/menus, times in UTC and '
            . 'store time. Filter by entity, status and date range (from/to in store time, YYYY-MM-DD[ HH:MM]). '
            . 'Statuses other than pending read at most the newest ' . self::HISTORY_ROW_CAP . ' items '
            . '(truncated: true when cut; '
            . 'total_releases counts only the items read).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES, false))
            ->string('status', fn (StringBuilder $s) => $s
                ->enum(['pending', 'any', 'success', 'partial', 'failed', 'cancelled'])
                ->description('Default pending.'))
            ->string('from', fn (StringBuilder $s) => $s->description('Earliest scheduled time, store timezone.'))
            ->string('to', fn (StringBuilder $s) => $s->description('Latest scheduled time, store timezone.'))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(500))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $filters = ['status' => $this->stringArg($arguments, 'status', 'pending')];
        $type = $this->stringArg($arguments, 'entity_type');
        if ($type !== null) {
            $filters['entity_type'] = $type;
            if (isset($arguments['id']) || isset($arguments['identifier'])) {
                $filters['entity_id'] = $this->contentLocator->locate($type, $arguments)['id'];
            }
        }
        $from = $this->stringArg($arguments, 'from');
        $to = $this->stringArg($arguments, 'to');
        if ($from !== null) {
            $filters['from'] = $this->scheduleTime->toUtc($from);
        }
        if ($to !== null) {
            $filters['to'] = $this->scheduleTime->toUtc(strlen($to) === 10 ? $to . ' 23:59:59' : $to);
        }

        $releases = [];
        $hidden = 0;
        $truncated = false;
        if ($filters['status'] !== 'pending') {
            // History grows without bound: read only the newest rows, one extra to tell whether the cap was hit.
            $filters['limit'] = self::HISTORY_ROW_CAP + 1;
        }
        $items = $this->scheduleService->getItems($filters);
        if (count($items) > self::HISTORY_ROW_CAP) {
            $truncated = true;
            $items = array_slice($items, count($items) - self::HISTORY_ROW_CAP);
        }
        // Items of content outside the caller's website/store view restriction are hidden like those of content
        // types the role may not schedule; one store view lookup per type covers all items.
        $this->contentLocator->prefetchScope(array_map(
            static fn (array $item): array => ['entity_type' => $item['entity_type'], 'id' => $item['entity_id']],
            $items
        ));
        foreach ($items as $item) {
            if (!$this->contentAcl->isAllowed($item['entity_type'], ContentAcl::VERB_SCHEDULING)
                || !$this->contentLocator->allowsRead($item['entity_type'], $item['entity_id'])
            ) {
                $hidden++;
                continue;
            }
            $releaseId = $item['release_id'];
            $releases[$releaseId] ??= [
                'release_id' => $releaseId,
                'name' => $item['release_name'],
                'status' => $item['release_status'],
                'time_scheduled_utc' => $item['time_scheduled'],
                'time_scheduled_local' => $this->scheduleTime->toLocal($item['time_scheduled']),
                'items' => [],
            ];
            $releases[$releaseId]['items'][] = [
                'item_id' => $item['item_id'],
                'entity_type' => $item['entity_type'],
                'entity_id' => $item['entity_id'],
                'name' => $item['name'],
                'status' => $item['status'],
                'settings' => json_decode($item['settings'] ?? 'null', true),
            ];
        }
        $totalReleases = count($releases);
        $releases = array_slice(array_values($releases), 0, $this->intArg($arguments, 'limit', 100) ?? 100);

        return $this->jsonResult(
            [
                'store_timezone' => $this->scheduleTime->getStoreTimezone(),
                'total_releases' => $totalReleases,
                'truncated' => $truncated,
                'releases' => $releases,
                'items_hidden_by_permissions' => $hidden,
            ],
            ['releases' => count($releases)]
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
