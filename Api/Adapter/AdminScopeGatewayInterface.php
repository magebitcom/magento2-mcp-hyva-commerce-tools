<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

/**
 * Hyvä Advanced Admin Permissions scope restriction. Only the adapter references Hyva\AdvancedAdminPermissionsScope
 * classes; use Model\Security\AdminScopeGuard, which checks the module is enabled first.
 */
interface AdminScopeGatewayInterface
{
    /**
     * Scopes an admin user may work in
     *
     * @param int $adminUserId
     * @return array
     * @phpstan-return array{restricted: bool, website_ids: list<int>, store_ids: list<int>}
     */
    public function allowedScopes(int $adminUserId): array;

    /**
     * Whether the admin user may see data of this website
     *
     * @param int $adminUserId
     * @param int $websiteId
     * @return bool
     */
    public function allowsWebsite(int $adminUserId, int $websiteId): bool;

    /**
     * Whether the admin user may change data of this website
     *
     * @param int $adminUserId
     * @param int $websiteId
     * @return bool
     */
    public function allowsWebsiteForWrite(int $adminUserId, int $websiteId): bool;
}
