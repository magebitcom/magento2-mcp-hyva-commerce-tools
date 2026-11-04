<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Tailwind;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\CmsTailwindGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

class TailwindStatus extends AbstractTool
{
    public const TOOL_NAME = 'hyva_cms.tailwind.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_tailwind_status';
    // Same resource as Hyvä's own cms_tailwind_daemon_status dashboard widget.
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Config::config_system';
    public const REASON_DAEMON_NOT_RESPONDING = 'daemon_not_responding';
    private const CONFIG_PATH_STRATEGY = 'hyva_cms_tailwind/hyva_cms_bridge/compilation_strategy';
    private const CONFIG_PATH_MANAGEMENT = 'hyva_cms_tailwind/general/daemon_management';
    private const CONFIG_LOCATION = 'Stores > Configuration > Hyvä Commerce > Hyvä CMS > CMS Tailwind Compilation';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param CmsTailwindGatewayInterface $tailwind
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly CmsTailwindGatewayInterface $tailwind
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Hyvä CMS Tailwind Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Show whether the Hyvä CMS Tailwind compiler daemon is running, how it is managed (off, cron), the '
            . 'compilation strategy of the Liveview Editor (in-browser or daemon), the compiler version, the daemon '
            . 'process (pid, start time), a pending restart, var/ files it cannot write and the last log line. '
            . 'When healthy is false, reason says why (Hyvä only checks that the daemon answers on this host) and the '
            . 'hint says whether that affects the editor and what to do next.';
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
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::CMS_TAILWIND)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::CMS_TAILWIND)
            );
        }
        $status = $this->tailwind->status();
        // Hyvä's is_healthy (DaemonStatusReporter) is DaemonManager::isRunning(): the daemon on this host answers
        // its /health request. It is the same check for every compilation strategy; nothing else is checked.
        $reason = $status['healthy'] ? null : self::REASON_DAEMON_NOT_RESPONDING;
        $hint = $reason === null
            ? null
            : $this->hint($status['compilation_strategy'], $status['compiler_version'], $status['daemon_management']);
        return $this->jsonResult([
            'healthy' => $status['healthy'],
            'daemon_management' => $status['daemon_management'],
            'compilation_strategy' => $status['compilation_strategy'],
            'compiler_version' => $status['compiler_version'],
            'daemon' => $status['daemon'],
            'restart_pending' => $status['restart_pending'],
            'non_writable_files' => $status['non_writable_files'],
            'last_log_line' => $status['last_log_line'],
            'reason' => $reason,
            'hint' => $hint,
        ], ['healthy' => $status['healthy']]);
    }

    /**
     * Explain the failed daemon health check for the active strategy and name the next step.
     *
     * @param string $strategy
     * @param string $compilerVersion
     * @param string $management
     * @return string
     */
    private function hint(string $strategy, string $compilerVersion, string $management): string
    {
        // compiler_version "daemon" forces the daemon strategy whatever the strategy field says.
        if ($strategy === 'in-browser' && $compilerVersion !== 'daemon') {
            return 'healthy only checks whether the Tailwind daemon answers on this host, and it does not. '
                . 'The compilation strategy is in-browser: the Hyvä CMS Liveview Editor compiles Tailwind in the '
                . 'browser and does not use the daemon, so the editor is not affected. To compile on the server '
                . 'instead, set ' . self::CONFIG_PATH_STRATEGY . ' to "daemon" (' . self::CONFIG_LOCATION
                . ') and start the daemon.';
        }
        $hint = 'The Tailwind daemon is not running: Tailwind classes in newly saved CMS content are not compiled '
            . 'until the daemon runs. ';
        return $hint . match ($management) {
            'cron' => 'Daemon management is "cron": Hyvä\'s watchdog cron starts it within a minute. Check that '
                . 'Magento cron is running, then see last_log_line.',
            'on_demand' => 'Daemon management is "on_demand": the first compile request starts it. If it stays '
                . 'down, see last_log_line.',
            default => 'Daemon management is "off", so nothing starts it: use the Start button under '
                . self::CONFIG_LOCATION . ', or let cron start it: bin/magento config:set '
                . self::CONFIG_PATH_MANAGEMENT . ' cron.',
        };
    }
}
