<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Usage;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\AiWorker\AbstractAiWorkerTool;

class UsageSummary extends AbstractAiWorkerTool
{
    public const TOOL_NAME = 'hyva_ai.usage.summary';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_ai_usage_summary';

    private const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2}(:\\d{2})?)?$';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Hyvä AI Worker Token Usage';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Sum the AI tokens used by Hyvä AI Worker provider calls (input and output), grouped by day, provider, '
            . 'model or job type, for a date range. Hyvä keeps no quota or credit balance; this is the recorded '
            . 'usage of jobs still within the retention period. Read only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('group_by', fn (StringBuilder $s) => $s->enum(['day', 'provider', 'model', 'type'])
                ->description('Default day.'))
            ->string('from', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN))
            ->string('to', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeAiWorker(array $arguments): ToolResultInterface
    {
        $to = $this->stringArg($arguments, 'to');
        $rows = $this->aiWorker->usage(
            $this->stringArg($arguments, 'group_by') ?? 'day',
            $this->stringArg($arguments, 'from'),
            $to !== null && strlen($to) === 10 ? $to . ' 23:59:59' : $to
        );
        $totals = ['calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }
        return $this->jsonResult(['totals' => $totals, 'rows' => $rows], $totals);
    }
}
