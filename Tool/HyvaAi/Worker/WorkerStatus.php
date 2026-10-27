<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Worker;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\AiWorker\AbstractAiWorkerTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

class WorkerStatus extends AbstractAiWorkerTool
{
    public const TOOL_NAME = 'hyva_ai.worker.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_ai_worker_status';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AiWorker::config';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Hyvä AI Worker Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Show whether the Hyvä AI Worker is set up and healthy: enabled, external or PHP worker, whether it is '
            . 'fully configured (secrets present and long enough; secrets themselves are never shown), bulk '
            . 'transport, rate limit, retention, and the scheduler health (cron running, active jobs, problems). '
                . 'ready/issues '
            . 'sum up what stops jobs from running. '
            . 'Checks the external worker\'s health endpoint (3 s timeout, cached 30 s).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeAiWorker(array $arguments): ToolResultInterface
    {
        $status = $this->aiWorker->status();
        // Hyvä's scheduler health can say "healthy" while nothing can run; spell out what blocks jobs.
        $issues = [];
        if (empty($status['enabled'])) {
            $issues[] = 'The AI Worker is disabled.';
        } elseif (empty($status['configured'])) {
            $issues[] = !empty($status['uses_php_worker']) && empty($status['php_worker_enabled'])
                ? 'Not configured: no external worker URL is set and the PHP worker is disabled; enable one of them. '
                    . 'Jobs cannot run until then.'
                : 'Not configured: the worker URL and both secrets (token signing, key encryption) must be set '
                    . 'and differ. Jobs cannot run until then.';
        }
        $scheduler = is_array($status['scheduler'] ?? null) ? $status['scheduler'] : [];
        // With active jobs Hyvä's own problems already report the stale cron.
        if (!empty($scheduler['cron_stale']) && Cast::int($scheduler['active_jobs'] ?? 0) === 0) {
            $issues[] = 'Cron is not running (last run: ' . Cast::string($scheduler['cron_last_run'] ?? 'never')
                . '); bulk jobs are never picked up.';
        }
        foreach (is_array($scheduler['problems'] ?? null) ? $scheduler['problems'] : [] as $problem) {
            $issues[] = is_string($problem) ? $problem : (string) json_encode($problem);
        }
        return $this->jsonResult(['ready' => $issues === [], 'issues' => $issues] + $status, [
            'enabled' => $status['enabled'] ?? false,
            'ready' => $issues === [],
        ]);
    }
}
