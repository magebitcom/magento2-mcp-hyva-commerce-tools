<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\AdminPermissions;

use Magento\Framework\Exception\LocalizedException;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\AdvancedAdminPermissionsScope\Model\AdminScopeResolver;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Adapter over Hyvä Advanced Admin Permissions scope restriction.
 *
 * Uses getForUser(): the resolver's request-based lookup has no admin user in the MCP (frontend) area.
 */
class AdminScopeGateway implements AdminScopeGatewayInterface
{
    /**
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices
    ) {
    }

    /**
     * @inheritDoc
     */
    public function allowedScopes(int $adminUserId): array
    {
        $scopes = $this->scopeResolver()->getForUser($adminUserId);
        return [
            'restricted' => $scopes->isRestricted(),
            'website_ids' => Cast::intList($scopes->getWebsiteIds()),
            'store_ids' => Cast::intList($scopes->getStoreIds()),
        ];
    }

    /**
     * @inheritDoc
     */
    public function allowsWebsite(int $adminUserId, int $websiteId): bool
    {
        return $this->scopeResolver()->getForUser($adminUserId)->allowsWebsite($websiteId);
    }

    /**
     * @inheritDoc
     */
    public function allowsWebsiteForWrite(int $adminUserId, int $websiteId): bool
    {
        return $this->scopeResolver()->getForUser($adminUserId)->allowsWebsiteForWrite($websiteId);
    }

    /**
     * AdminScopeResolver from the optional package, created on first use.
     *
     * @return AdminScopeResolver
     * @throws LocalizedException When the package is not installed.
     */
    private function scopeResolver(): AdminScopeResolver
    {
        return $this->optionalServices->get(AdminScopeResolver::class);
    }
}
