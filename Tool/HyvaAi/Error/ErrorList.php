<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Error;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\AiWorker\AbstractAiWorkerTool;

class ErrorList extends AbstractAiWorkerTool
{
    public const TOOL_NAME = 'hyva_ai.error.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_ai_error_list';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä AI Worker Errors';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List recent Hyvä AI Worker failures: failed or stale jobs with their error, failed bulk chunks of '
            . 'running scheduler jobs (retryable or not), and the scheduler problems from the health check. Use it '
            . 'to explain why AI generation or translation stopped working. Read only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(100)->description('Default 20.'))
            ->string('job_id', fn (StringBuilder $s) => $s->pattern('^[a-f0-9]{32}$'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeAiWorker(array $arguments): ToolResultInterface
    {
        $errors = $this->aiWorker->errors(
            $this->intArg($arguments, 'limit', 20) ?? 20,
            $this->stringArg($arguments, 'job_id')
        );
        $scheduler = $this->aiWorker->status()['scheduler'] ?? [];
        $errors['scheduler_problems'] = is_array($scheduler) ? ($scheduler['problems'] ?? []) : [];
        return $this->jsonResult($errors, [
            'failed_jobs' => count($errors['failed_jobs']),
            'failed_chunks' => count($errors['failed_chunks']),
        ]);
    }
}
