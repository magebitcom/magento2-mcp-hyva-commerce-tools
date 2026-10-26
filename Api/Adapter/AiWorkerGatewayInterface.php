<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

/**
 * Hyvä AI Worker monitoring (read only). Only the adapter references Hyva\AiWorker classes; tools reach it through a
 * proxy, after the ai_worker feature check. Secrets are never returned.
 *
 * @phpstan-type JobRow array{job_id: string, type: string, task: string, mode: string, managed_by: string,
 *     status: string, admin_user_id: int|null, admin_username: string, store_id: int|null, provider: string,
 *     model: string, items_total: int, items_complete: int, items_failed: int, error_code: string,
 *     error_message: string, pinned: bool, parent_job_id: string, created_at: string, started_at: string,
 *     finished_at: string, expires_at: string}
 */
interface AiWorkerGatewayInterface
{
    /**
     * Worker settings (no secrets) and scheduler health
     *
     * Health resolves the transport, which calls the external worker's /v1/health (3 s timeout, cached 30 s).
     *
     * @return array<string, mixed>
     */
    public function status(): array;

    /**
     * Jobs, newest first
     *
     * @param array $filters status, type, mode, provider (string lists), admin_user_id, store_id (int), from, to
     * @param int $page
     * @param int $pageSize
     * @return array
     * @phpstan-param array{status?: list<string>, type?: list<string>, mode?: list<string>,
     *     provider?: list<string>, admin_user_id?: int, store_id?: int, from?: string, to?: string} $filters
     * @phpstan-return array{total: int, items: list<JobRow>}
     */
    public function jobs(array $filters, int $page, int $pageSize): array;

    /**
     * One job with item counts and a page of items, or null
     *
     * @param string $jobId
     * @param list<string> $itemStatuses
     * @param int $itemLimit
     * @param bool $includePayload Include the job payload and item results (may hold prompts and store data)
     * @return array<string, mixed>|null
     */
    public function job(string $jobId, array $itemStatuses, int $itemLimit, bool $includePayload): ?array;

    /**
     * Token usage of delivered provider calls, grouped
     *
     * @param string $groupBy day|provider|model|type
     * @param string|null $from
     * @param string|null $to
     * @return list<array{group: string, calls: int, input_tokens: int, output_tokens: int}>
     */
    public function usage(string $groupBy, ?string $from, ?string $to): array;

    /**
     * Recent failures: failed or stale jobs and failed scheduler chunks
     *
     * @param int $limit
     * @param string|null $jobId
     * @return array{failed_jobs: list<array<string, mixed>>, failed_chunks: list<array<string, mixed>>}
     */
    public function errors(int $limit, ?string $jobId): array;
}
