<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Plugin\Authorization;

use Hyva\AdvancedAdminPermissions\Api\AdminUserIdResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Acl\McpCallScope;

/**
 * Lets Hyvä Advanced Admin Permissions evaluate the MCP caller's whole role set (union, deny roles, expiry).
 *
 * Its UnionRoleAuthorizationPlugin finds the admin through AdminUserIdResolverInterface, which knows no admin on the
 * /mcp frontend route, so it would fall back to the single role from McpRoleLocatorPlugin. Inside a tool call the
 * resolver now answers with the MCP caller's admin user id.
 */
class McpAdminUserIdPlugin
{
    /**
     * @param CallerContext $callerContext
     * @param McpCallScope $callScope
     */
    public function __construct(
        private readonly CallerContext $callerContext,
        private readonly McpCallScope $callScope
    ) {
    }

    /**
     * After get user id.
     *
     * @param AdminUserIdResolverInterface $subject
     * @param int|null $result
     * @return int|null
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterGetUserId(AdminUserIdResolverInterface $subject, ?int $result): ?int
    {
        if ($result !== null || !$this->callScope->isActive()) {
            return $result;
        }
        return $this->callerContext->adminUserId() ?? $result;
    }
}
