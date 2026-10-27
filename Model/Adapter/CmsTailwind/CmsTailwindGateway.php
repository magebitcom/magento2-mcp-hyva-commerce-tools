<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\CmsTailwind;

use Hyva\CmsTailwindCompiler\Model\DaemonStatusReporter;
use Magebit\McpHyvaCommerceTools\Api\Adapter\CmsTailwindGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Adapter over Hyvä CMS Tailwind Compiler (DaemonStatusReporter, the payload of Hyvä's own status widget).
 */
class CmsTailwindGateway implements CmsTailwindGatewayInterface
{
    /**
     * @param OptionalServiceFactory $optionalServices
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function status(): array
    {
        $payload = $this->optionalServices->get(DaemonStatusReporter::class)->getStatusPayload();
        $state = is_array($payload['daemon_state'] ?? null) ? $payload['daemon_state'] : null;
        $files = is_array($payload['non_writable_files'] ?? null) ? $payload['non_writable_files'] : [];
        $lastLine = $payload['last_log_line'] ?? null;
        return [
            'healthy' => !empty($payload['is_healthy']),
            'daemon_management' => $this->setting('hyva_cms_tailwind/general/daemon_management', 'off'),
            'compilation_strategy' => $this->setting(
                'hyva_cms_tailwind/hyva_cms_bridge/compilation_strategy',
                'in-browser'
            ),
            'compiler_version' => $this->setting('hyva_cms_tailwind_jit/general/compiler_version', ''),
            'daemon' => $state === null ? null : [
                'pid' => Cast::int($state['pid'] ?? 0),
                'started_at' => Cast::string($state['started_at'] ?? ''),
            ],
            'restart_pending' => !empty($payload['restart_pending']),
            'non_writable_files' => array_values(array_map('strval', array_filter($files, 'is_scalar'))),
            'last_log_line' => is_string($lastLine) ? $lastLine : null,
        ];
    }

    /**
     * String config value, or the default when unset or empty.
     *
     * @param string $path
     * @param string $default
     * @return string
     */
    private function setting(string $path, string $default): string
    {
        $value = $this->scopeConfig->getValue($path);
        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
