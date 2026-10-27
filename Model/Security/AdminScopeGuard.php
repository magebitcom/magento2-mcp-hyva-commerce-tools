<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Security;

use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Website/store scope restriction of the MCP admin (Hyvä Advanced Admin Permissions), for tools whose Hyvä
 * services do not apply it themselves. Unrestricted when the scope module is not enabled.
 */
class AdminScopeGuard
{
    private const SCOPE_MODULE = 'Hyva_AdvancedAdminPermissionsScope';

    /**
     * @param ModuleManager $moduleManager
     * @param AdminScopeGatewayInterface $gateway
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly AdminScopeGatewayInterface $gateway
    ) {
    }

    /**
     * Scopes the admin may work in
     *
     * @param int|null $adminUserId
     * @return array
     * @phpstan-return array{restricted: bool, website_ids: list<int>, store_ids: list<int>}
     */
    public function allowedScopes(?int $adminUserId): array
    {
        if ($adminUserId === null || !$this->moduleManager->isEnabled(self::SCOPE_MODULE)) {
            return ['restricted' => false, 'website_ids' => [], 'store_ids' => []];
        }
        return $this->gateway->allowedScopes($adminUserId);
    }

    /**
     * Whether the admin may see data of the website
     *
     * @param int|null $adminUserId
     * @param int $websiteId
     * @return bool
     */
    public function allowsWebsite(?int $adminUserId, int $websiteId): bool
    {
        if ($adminUserId === null || !$this->moduleManager->isEnabled(self::SCOPE_MODULE)) {
            return true;
        }
        return $this->gateway->allowsWebsite($adminUserId, $websiteId);
    }

    /**
     * Whether the admin may change data of the website
     *
     * @param int|null $adminUserId
     * @param int $websiteId
     * @return bool
     */
    public function allowsWebsiteForWrite(?int $adminUserId, int $websiteId): bool
    {
        if ($adminUserId === null || !$this->moduleManager->isEnabled(self::SCOPE_MODULE)) {
            return true;
        }
        return $this->gateway->allowsWebsiteForWrite($adminUserId, $websiteId);
    }
}
