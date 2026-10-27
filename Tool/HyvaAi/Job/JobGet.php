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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\AiWorker\AbstractAiWorkerTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\AuthorizationException;

class JobGet extends AbstractAiWorkerTool
{
    public const TOOL_NAME = 'hyva_ai.job.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_ai_job_get';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AiWorker::manage';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Hyvä AI Worker Job';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one Hyvä AI Worker job: status, progress counts, error, and its items (entity, status, review '
            . 'state, error, token usage). include_payload adds the job request and item results (truncated; they '
            . 'can contain prompts and store data). Your own jobs, or any job with Hyva_AiWorker::jobs. Read only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('job_id', fn (StringBuilder $s) => $s->pattern('^[a-f0-9]{32}$')->required())
            ->array('item_status', fn (ArrayBuilder $a) => $a->ofStrings(fn (StringBuilder $s) => $s
                ->enum(['pending', 'processing', 'complete', 'failed', 'canceled']))->maxItems(5))
            ->integer('item_limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200)
                ->description('Items to return (default 50).'))
            ->boolean('include_payload', fn (BooleanBuilder $b) => $b->description('Default false.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeAiWorker(array $arguments): ToolResultInterface
    {
        $jobId = $this->requireString($arguments, 'job_id');
        $job = $this->aiWorker->job(
            $jobId,
            array_values(array_map([Cast::class, 'string'], Cast::array($arguments['item_status'] ?? []))),
            max(1, min(200, $this->intArg($arguments, 'item_limit', 50) ?? 50)),
            $this->boolArg($arguments, 'include_payload')
        );
        if ($job === null) {
            return $this->softError('No AI Worker job with that id (finished jobs are removed after the retention '
                . 'period).');
        }
        // Like Hyvä's Job/Status: a job without an owner (CLI, scheduler) is nobody's own job.
        $owner = $job['admin_user_id'] ?? null;
        if (!$this->seesAllJobs() && ($owner === null || $owner !== $this->adminUserId())) {
            throw new AuthorizationException(__('You may only read your own AI Worker jobs.'));
        }
        return $this->jsonResult($job, ['job_id' => $jobId]);
    }
}
