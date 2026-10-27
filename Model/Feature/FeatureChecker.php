<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Feature;

use Magebit\Mcp\Api\LoggerInterface;
use Composer\InstalledVersions;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Throwable;

class FeatureChecker implements FeatureCheckerInterface
{
    /**
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param ModuleManager $moduleManager
     * @param CallerContext $callerContext
     * @param AuthorizationInterface $authorization
     * @param LoggerInterface $logger
     * @param array $features
     * @phpstan-param array<string, array{label?: string, package?: string, acl?: string, modules?: array<int|string,
     *     string>}> $features
     */
    public function __construct(
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ModuleManager $moduleManager,
        private readonly CallerContext $callerContext,
        private readonly AuthorizationInterface $authorization,
        private readonly LoggerInterface $logger,
        private readonly array $features = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isAvailable(string $feature): bool
    {
        return $this->getStatus($feature)['available'];
    }

    /**
     * @inheritDoc
     */
    public function getStatus(string $feature): array
    {
        $definition = $this->features[$feature] ?? [];
        $package = (string) ($definition['package'] ?? '');
        $aclResource = isset($definition['acl']) ? (string) $definition['acl'] : null;

        $modules = [];
        $installed = $definition !== [];
        $enabled = $definition !== [];
        foreach ($definition['modules'] ?? [] as $moduleName) {
            $moduleInstalled = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName) !== null;
            $moduleEnabled = $moduleInstalled && $this->moduleManager->isEnabled($moduleName);
            $modules[$moduleName] = ['installed' => $moduleInstalled, 'enabled' => $moduleEnabled];
            $installed = $installed && $moduleInstalled;
            $enabled = $enabled && $moduleEnabled;
        }

        $aclAllowed = $aclResource !== null ? $this->isAclAllowed($aclResource) : null;

        return [
            'feature' => $feature,
            'label' => (string) ($definition['label'] ?? $feature),
            'available' => $installed && $enabled && $aclAllowed !== false,
            'installed' => $installed,
            'enabled' => $enabled,
            'version' => $this->getPackageVersion($package),
            'package' => $package,
            'acl_resource' => $aclResource,
            'acl_allowed' => $aclAllowed,
            'modules' => $modules,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAll(): array
    {
        $result = [];
        foreach (array_keys($this->features) as $feature) {
            $result[$feature] = $this->getStatus($feature);
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getUnavailableResponse(string $feature): array
    {
        $status = $this->getStatus($feature);
        $label = $status['label'];

        if (!$status['installed']) {
            $reason = sprintf('%s is not installed.', $label);
            $hint = sprintf('Install %s with composer to enable these tools.', $status['package'] ?: $label);
        } elseif (!$status['enabled']) {
            $disabled = array_keys(array_filter($status['modules'], static fn (array $m): bool => !$m['enabled']));
            $reason = sprintf('%s is installed but disabled (%s).', $label, implode(', ', $disabled));
            $hint = sprintf('Run bin/magento module:enable %s and setup:upgrade.', implode(' ', $disabled));
        } else {
            $reason = sprintf('The admin role behind this token lacks %s.', (string) $status['acl_resource']);
            $hint = 'Grant that resource to the admin role that owns the MCP token.';
        }

        return ['available' => false, 'feature' => $feature, 'reason' => $reason, 'hint' => $hint];
    }

    /**
     * The MCP admin when called over /mcp, otherwise the admin session (dashboard widgets).
     *
     * @param string $resource
     * @return bool|null Null when no admin context exists (CLI).
     */
    private function isAclAllowed(string $resource): ?bool
    {
        $allowed = $this->callerContext->isAllowed($resource);
        if ($allowed !== null) {
            return $allowed;
        }
        try {
            return $this->authorization->isAllowed($resource);
        } catch (Throwable $e) {
            // Unknown: the feature is reported without an ACL verdict.
            $this->logger->warning('Hyvä feature check: could not evaluate an ACL resource.', [
                'resource' => $resource,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Get package version.
     *
     * @param string $package
     * @return string|null
     */
    private function getPackageVersion(string $package): ?string
    {
        if ($package === '' || !InstalledVersions::isInstalled($package)) {
            return null;
        }
        return InstalledVersions::getPrettyVersion($package);
    }
}
