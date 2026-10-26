<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

/**
 * Hyvä Advanced Admin Permissions reads (effective permissions, role assignment audit). Only the adapter references
 * Hyva\AdvancedAdminPermissions classes; tools reach it through a proxy, after the admin_permissions feature check.
 * Scope restrictions go through AdminScopeGatewayInterface.
 */
interface AdminPermissionsGatewayInterface
{
    /**
     * Effective permissions of an admin user: roles, lapsed and negative roles, and every ACL resource
     *
     * @param int $userId
     * @return array
     * @phpstan-return array{roles: array<int, string>, inactive_roles: list<int>, negative_roles: list<int>,
     *     resources: list<array{id: string, title: string, level: int, allowed: bool, granted_by: list<int>,
     *     denied_by: list<int>}>}
     */
    public function effectivePermissions(int $userId): array;

    /**
     * Role assignment audit entries, newest first
     *
     * @param array $filters target_user_id, actor_user_id (int), action (string list), from, to (dates)
     * @param int $page
     * @param int $pageSize
     * @return array
     * @phpstan-param array{target_user_id?: int, actor_user_id?: int, action?: list<string>, from?: string,
     *     to?: string} $filters
     * @phpstan-return array{total: int, items: list<array<string, mixed>>}
     */
    public function audit(array $filters, int $page, int $pageSize): array;
}
