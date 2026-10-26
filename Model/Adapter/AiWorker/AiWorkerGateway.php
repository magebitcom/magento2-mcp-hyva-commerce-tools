<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\AiWorker;

use Magento\Framework\Exception\LocalizedException;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\AiWorker\Model\Config;
use Hyva\AiWorker\Model\JobManager;
use Hyva\AiWorker\Model\Scheduler\SchedulerHealth;
use Hyva\AiWorker\Model\Scheduler\SchedulerLedger;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AiWorkerGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Adapter over Hyvä AI Worker 1.0.x.
 *
 * Hyvä has no job list service (its admin grid reads hyva_ai_worker_job directly) and no usage service (token counts
 * live on hyva_ai_worker_delivery), so those two read the tables; job detail and health use Hyvä's own services.
 *
 * @phpstan-import-type JobRow from AiWorkerGatewayInterface
 */
class AiWorkerGateway implements AiWorkerGatewayInterface
{
    private const JOB_TABLE = 'hyva_ai_worker_job';
    private const DELIVERY_TABLE = 'hyva_ai_worker_delivery';
    private const TEXT_LIMIT = 2000;

    /**
     * @param OptionalServiceFactory $optionalServices
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function status(): array
    {
        $url = $this->config()->getWorkerUrl();
        return [
            'enabled' => $this->config()->isEnabled(),
            'external_worker_enabled' => $this->config()->isExternalWorkerEnabled(),
            'php_worker_enabled' => $this->config()->isPhpWorkerEnabled(),
            'uses_php_worker' => $this->config()->usesPhpWorker(),
            // Host only: the URL is configuration, not a secret, but keep credentials or tokens in it out.
            'worker_host' => (string) parse_url($url, PHP_URL_HOST),
            'configured' => $this->config()->isConfigured(),
            'secrets_from_environment' => $this->config()->hasEnvironmentSecrets(),
            'token_signing_secret_too_short' => $this->config()->isTokenSigningSecretTooShort(),
            'key_encryption_secret_too_short' => $this->config()->isKeyEncryptionSecretTooShort(),
            'bulk_transport' => $this->config()->getBulkTransport(),
            'rate_limit_per_hour' => $this->config()->getRateLimitPerHour(),
            'job_retention_days' => $this->config()->getJobRetentionDays(),
            'bulk_max_attempts' => $this->config()->getBulkMaxAttempts(),
            'bulk_chunk_size' => $this->config()->getBulkChunkSize(),
            'max_concurrent_deliveries' => $this->config()->getMaxConcurrentDeliveries(),
            'scheduler' => $this->schedulerHealth()->check(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function jobs(array $filters, int $page, int $pageSize): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->resourceConnection->getTableName(self::JOB_TABLE));
        foreach (['status', 'type', 'mode', 'provider'] as $field) {
            if (!empty($filters[$field])) {
                $select->where($field . ' IN (?)', $filters[$field]);
            }
        }
        foreach (['admin_user_id', 'store_id'] as $field) {
            if (isset($filters[$field])) {
                $select->where($field . ' = ?', $filters[$field]);
            }
        }
        if (isset($filters['from'])) {
            $select->where('created_at >= ?', $filters['from']);
        }
        if (isset($filters['to'])) {
            $select->where('created_at <= ?', $filters['to']);
        }
        $count = (clone $select)->reset(Select::COLUMNS)->columns(['n' => 'COUNT(*)']);
        $total = (int) $connection->fetchOne($count);
        $select->order('created_at DESC')->limitPage($page, $pageSize);
        $items = [];
        foreach ($connection->fetchAll($select) as $row) {
            $items[] = $this->jobRow($row);
        }
        return ['total' => $total, 'items' => $items];
    }

    /**
     * @inheritDoc
     */
    public function job(string $jobId, array $itemStatuses, int $itemLimit, bool $includePayload): ?array
    {
        $job = $this->jobManager()->getJob($jobId);
        if ($job === null) {
            return null;
        }
        $items = [];
        $filters = $itemStatuses !== [] ? ['status' => $itemStatuses] : [];
        foreach ($this->jobManager()->getItems($jobId, $filters, $itemLimit) as $item) {
            // Item requests and results can hold prompts and store data: only on request, and truncated.
            foreach (['payload', 'result', 'reviewed_result'] as $key) {
                if (!$includePayload) {
                    unset($item[$key]);
                } elseif (isset($item[$key])) {
                    $value = is_string($item[$key]) ? $item[$key] : (string) json_encode($item[$key]);
                    $item[$key] = mb_strimwidth($value, 0, self::TEXT_LIMIT, '…');
                }
            }
            $items[] = $item;
        }
        $row = $this->jobRow($job);
        if ($includePayload) {
            $payload = $job['payload'] ?? null;
            $encoded = is_string($payload) ? $payload : (string) json_encode($payload);
            $row['payload'] = mb_strimwidth($encoded, 0, self::TEXT_LIMIT, '…');
        }
        return $row + [
            'item_counts' => [
                'total' => $this->jobManager()->countItems($jobId),
                'complete' => $this->jobManager()->countItems($jobId, ['complete']),
                'failed' => $this->jobManager()->countItems($jobId, ['failed']),
                'pending' => $this->jobManager()->countItems($jobId, ['pending', 'processing']),
            ],
            'items' => $items,
        ];
    }

    /**
     * @inheritDoc
     */
    public function usage(string $groupBy, ?string $from, ?string $to): array
    {
        $connection = $this->resourceConnection->getConnection();
        $group = match ($groupBy) {
            'provider' => 'j.provider',
            'model' => 'j.model',
            'type' => 'j.type',
            default => 'DATE(d.created_at)',
        };
        $select = $connection->select()
            ->from(['d' => $this->resourceConnection->getTableName(self::DELIVERY_TABLE)], [
                'group' => new \Zend_Db_Expr($group),
                'calls' => new \Zend_Db_Expr('COUNT(*)'),
                'input_tokens' => new \Zend_Db_Expr('COALESCE(SUM(d.usage_input_tokens), 0)'),
                'output_tokens' => new \Zend_Db_Expr('COALESCE(SUM(d.usage_output_tokens), 0)'),
            ])
            ->join(['j' => $this->resourceConnection->getTableName(self::JOB_TABLE)], 'j.job_id = d.job_id', [])
            ->group(new \Zend_Db_Expr($group))
            ->order(new \Zend_Db_Expr($group . ' DESC'))
            ->limit(366);
        if ($from !== null) {
            $select->where('d.created_at >= ?', $from);
        }
        if ($to !== null) {
            $select->where('d.created_at <= ?', $to);
        }
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'group' => Cast::string($row['group'] ?? ''),
                'calls' => Cast::int($row['calls'] ?? 0),
                'input_tokens' => Cast::int($row['input_tokens'] ?? 0),
                'output_tokens' => Cast::int($row['output_tokens'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function errors(int $limit, ?string $jobId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::JOB_TABLE), [
                'job_id', 'type', 'task', 'status', 'error_code', 'error_message', 'admin_username', 'updated_at',
            ])
            ->where('status IN (?)', ['failed', 'stale'])
            ->order('updated_at DESC')
            ->limit($limit);
        if ($jobId !== null) {
            $select->where('job_id = ?', $jobId);
        }
        $failedJobs = [];
        foreach ($connection->fetchAll($select) as $row) {
            $row['error_message'] = mb_strimwidth(Cast::string($row['error_message'] ?? ''), 0, 500, '…');
            $failedJobs[] = $row;
        }
        $chunks = [];
        foreach ($this->schedulerLedger()->findLatestFailedChunks($limit, $jobId) as $chunk) {
            $chunks[] = is_array($chunk) ? $chunk : ['value' => $chunk];
        }
        return ['failed_jobs' => $failedJobs, 'failed_chunks' => $chunks];
    }

    /**
     * Job table row as a tool row (payload left out).
     *
     * @param array $row
     * @return array
     * @phpstan-param array<string, mixed> $row
     * @phpstan-return JobRow
     */
    private function jobRow(array $row): array
    {
        $nullableInt = static fn (mixed $v): ?int => $v === null || $v === '' ? null : Cast::int($v);
        return [
            'job_id' => Cast::string($row['job_id'] ?? ''),
            'type' => Cast::string($row['type'] ?? ''),
            'task' => Cast::string($row['task'] ?? ''),
            'mode' => Cast::string($row['mode'] ?? ''),
            'managed_by' => Cast::string($row['managed_by'] ?? ''),
            'status' => Cast::string($row['status'] ?? ''),
            'admin_user_id' => $nullableInt($row['admin_user_id'] ?? null),
            'admin_username' => Cast::string($row['admin_username'] ?? ''),
            'store_id' => $nullableInt($row['store_id'] ?? null),
            'provider' => Cast::string($row['provider'] ?? ''),
            'model' => Cast::string($row['model'] ?? ''),
            'items_total' => Cast::int($row['items_total'] ?? 0),
            'items_complete' => Cast::int($row['items_complete'] ?? 0),
            'items_failed' => Cast::int($row['items_failed'] ?? 0),
            'error_code' => Cast::string($row['error_code'] ?? ''),
            'error_message' => mb_strimwidth(Cast::string($row['error_message'] ?? ''), 0, 500, '…'),
            'pinned' => Cast::bool($row['pinned'] ?? false),
            'parent_job_id' => Cast::string($row['parent_job_id'] ?? ''),
            'created_at' => Cast::string($row['created_at'] ?? ''),
            'started_at' => Cast::string($row['started_at'] ?? ''),
            'finished_at' => Cast::string($row['finished_at'] ?? ''),
            'expires_at' => Cast::string($row['expires_at'] ?? ''),
        ];
    }

    /**
     * Config from the optional package, created on first use.
     *
     * @return Config
     * @throws LocalizedException When the package is not installed.
     */
    private function config(): Config
    {
        return $this->optionalServices->get(Config::class);
    }

    /**
     * JobManager from the optional package, created on first use.
     *
     * @return JobManager
     * @throws LocalizedException When the package is not installed.
     */
    private function jobManager(): JobManager
    {
        return $this->optionalServices->get(JobManager::class);
    }

    /**
     * SchedulerHealth from the optional package, created on first use.
     *
     * @return SchedulerHealth
     * @throws LocalizedException When the package is not installed.
     */
    private function schedulerHealth(): SchedulerHealth
    {
        return $this->optionalServices->get(SchedulerHealth::class);
    }

    /**
     * SchedulerLedger from the optional package, created on first use.
     *
     * @return SchedulerLedger
     * @throws LocalizedException When the package is not installed.
     */
    private function schedulerLedger(): SchedulerLedger
    {
        return $this->optionalServices->get(SchedulerLedger::class);
    }
}
