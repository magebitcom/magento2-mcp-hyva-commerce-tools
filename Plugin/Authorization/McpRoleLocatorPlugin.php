<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Plugin\Authorization;

use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Acl\McpCallScope;
use Magento\Framework\Authorization\RoleLocatorInterface;

/**
 * Lets Magento authorization see the MCP caller's admin role while a tool runs.
 *
 * /mcp is a frontend route, where the role locator has no admin session and returns no role. Hyvä Commerce 1.4
 * checks permissions inside its own services (ContentTypeAuthorization::assertAllowed() through
 * AuthorizationInterface, e.g. VersionHistoryManager::restoreVersion()), so without this every such check
 * refuses MCP callers, administrators included. The role is only supplied inside a tool's execute()
 * (McpCallScope, opened by McpCallScopePlugin) and only for the authenticated MCP caller; the core never clears
 * its context, so the scope is what ends the hand-over. Every other frontend request keeps the default (no role).
 */
class McpRoleLocatorPlugin
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
     * After get acl role id.
     *
     * @param RoleLocatorInterface $subject
     * @param mixed $result Role id; the default frontend locator returns ''.
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetAclRoleId(RoleLocatorInterface $subject, mixed $result): mixed
    {
        if (($result !== null && $result !== '') || !$this->callScope->isActive()) {
            return $result;
        }
        return $this->callerContext->aclRole() ?? $result;
    }
}
