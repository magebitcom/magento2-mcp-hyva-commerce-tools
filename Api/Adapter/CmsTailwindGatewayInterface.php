<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

/**
 * Hyvä CMS Tailwind compiler daemon status (read only). Only the adapter references Hyva\CmsTailwindCompiler classes;
 * tools reach it through a proxy, after the cms_tailwind feature check.
 */
interface CmsTailwindGatewayInterface
{
    /**
     * Daemon health and the settings that decide whether Tailwind classes get compiled
     *
     * Server internals of Hyvä's payload (host name, log and restart-flag paths) are left out.
     *
     * @return array
     * @phpstan-return array{healthy: bool, daemon_management: string, compilation_strategy: string,
     *     compiler_version: string, daemon: array{pid: int, started_at: string}|null, restart_pending: bool,
     *     non_writable_files: list<string>, last_log_line: string|null}
     */
    public function status(): array;
}
