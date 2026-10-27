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
            . 'The hint says when Tailwind classes in newly saved CMS content are not being compiled.';
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
        // compiler_version "daemon" forces the daemon strategy whatever the strategy field says.
        $usesDaemon = $status['compilation_strategy'] !== 'in-browser' || $status['compiler_version'] === 'daemon';
        $hint = !$status['healthy'] && $usesDaemon
            ? 'The Tailwind daemon is not running: Tailwind classes in newly saved CMS content are not compiled '
                . 'until the daemon runs.'
            : null;
        return $this->jsonResult([
            'healthy' => $status['healthy'],
            'daemon_management' => $status['daemon_management'],
            'compilation_strategy' => $status['compilation_strategy'],
            'compiler_version' => $status['compiler_version'],
            'daemon' => $status['daemon'],
            'restart_pending' => $status['restart_pending'],
            'non_writable_files' => $status['non_writable_files'],
            'last_log_line' => $status['last_log_line'],
            'hint' => $hint,
        ], ['healthy' => $status['healthy']]);
    }
}
