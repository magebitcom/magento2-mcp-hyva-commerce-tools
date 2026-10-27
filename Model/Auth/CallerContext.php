<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Auth;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magento\Framework\Exception\LocalizedException;

/**
 * The admin behind the current MCP call.
 *
 * The only class in this module that uses the core's non-API caller classes (Model\Auth\AuthenticatedContextProvider,
 * Model\Acl\AclChecker). Magebit_Mcp has no public caller-context API yet (core change proposal in state.md); when it
 * gets one, only this adapter changes.
 */
class CallerContext
{
    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param AclChecker $aclChecker
     */
    public function __construct(
        private readonly AuthenticatedContextProvider $contextProvider,
        private readonly AclChecker $aclChecker
    ) {
    }

    /**
     * Admin user id of the MCP caller, or null outside an authenticated MCP request.
     *
     * @return int|null
     */
    public function adminUserId(): ?int
    {
        try {
            $id = $this->contextProvider->get()->getAdminUserId();
        } catch (LocalizedException) {
            return null;
        }
        return $id > 0 ? $id : null;
    }

    /**
     * Admin username of the MCP caller, or null.
     *
     * @return string|null
     */
    public function adminUsername(): ?string
    {
        try {
            $name = (string) $this->contextProvider->get()->adminUser->getUserName();
        } catch (LocalizedException) {
            return null;
        }
        return $name !== '' ? $name : null;
    }

    /**
     * ACL role id of the MCP caller's admin user (primary role), or null.
     *
     * @return string|null
     */
    public function aclRole(): ?string
    {
        try {
            $role = (string) $this->contextProvider->get()->adminUser->getAclRole();
        } catch (LocalizedException) {
            return null;
        }
        return $role !== '' ? $role : null;
    }

    /**
     * Whether the MCP caller's role allows an ACL resource; null outside an authenticated MCP request.
     *
     * Uses the core's own role check, so it answers the same as the core's tool ACL gate.
     *
     * @param string $resource
     * @return bool|null
     */
    public function isAllowed(string $resource): ?bool
    {
        try {
            $user = $this->contextProvider->get()->adminUser;
        } catch (LocalizedException) {
            return null;
        }
        return $this->aclChecker->isAllowed($user, $resource);
    }
}
