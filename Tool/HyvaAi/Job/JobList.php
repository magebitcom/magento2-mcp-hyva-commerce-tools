<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Job;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\AiWorker\AbstractAiWorkerTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

class JobList extends AbstractAiWorkerTool
{
    public const TOOL_NAME = 'hyva_ai.job.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_ai_job_list';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AiWorker::manage';

    private const STATUSES = ['pending', 'streaming', 'queued', 'running', 'review', 'complete', 'failed',
        'canceled', 'stale'];
    private const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2}(:\\d{2})?)?$';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä AI Worker Jobs';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List Hyvä AI Worker jobs (AI content generation, translations, bulk runs), newest first, with status, '
            . 'progress (items complete/failed), who started them, provider/model and the last error. Roles with '
            . 'Hyva_AiWorker::jobs see every job; others only their own. Read only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('status', fn (ArrayBuilder $a) => $a->ofStrings(fn (StringBuilder $s) => $s->enum(self::STATUSES))
                ->maxItems(9))
            ->array('type', fn (ArrayBuilder $a) => $a->ofStrings()->maxItems(20)
                ->description('Job types, e.g. cms_generate.'))
            ->string('mode', fn (StringBuilder $s) => $s->enum(['interactive', 'bulk']))
            ->string('provider', fn (StringBuilder $s) => $s->maxLength(64))
            ->integer('admin_user_id', fn (IntegerBuilder $i) => $i->minimum(1)
                ->description('Jobs started by this admin (ignored when you may only see your own).'))
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->string('from', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN))
            ->string('to', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeAiWorker(array $arguments): ToolResultInterface
    {
        $list = static fn (mixed $value): array => array_values(array_filter(
            array_map([Cast::class, 'string'], Cast::array($value)),
            static fn (string $v): bool => $v !== ''
        ));
        $mode = $this->stringArg($arguments, 'mode');
        $provider = $this->stringArg($arguments, 'provider');
        $to = $this->stringArg($arguments, 'to');
        $filters = array_filter([
            'status' => $list($arguments['status'] ?? []),
            'type' => $list($arguments['type'] ?? []),
            'mode' => $mode !== null ? [$mode] : [],
            'provider' => $provider !== null ? [$provider] : [],
        ]);
        $adminUserId = $this->intArg($arguments, 'admin_user_id');
        if ($adminUserId !== null) {
            $filters['admin_user_id'] = $adminUserId;
        }
        $storeId = $this->intArg($arguments, 'store_id');
        if ($storeId !== null) {
            $filters['store_id'] = $storeId;
        }
        $from = $this->stringArg($arguments, 'from');
        if ($from !== null) {
            $filters['from'] = $from;
        }
        if ($to !== null) {
            $filters['to'] = strlen($to) === 10 ? $to . ' 23:59:59' : $to;
        }
        $ownOnly = !$this->seesAllJobs();
        if ($ownOnly) {
            $filters['admin_user_id'] = $this->adminUserId() ?? -1;
        }
        $result = $this->aiWorker->jobs(
            $filters,
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult($result + ['own_jobs_only' => $ownOnly], ['total' => $result['total']]);
    }
}
