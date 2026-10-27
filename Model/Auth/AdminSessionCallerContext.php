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
use Magento\Authorization\Model\UserContextInterface;

/**
 * Caller context for the admin area (etc/adminhtml/di.xml): the dashboard widgets read content with this module's
 * own SQL, which Hyvä's scope guards do not see, so StoreScopeGuard applies the restriction of the logged-in admin
 * when there is no MCP caller.
 */
class AdminSessionCallerContext extends CallerContext
{
    /**
     * @param AuthenticatedContextProvider $contextProvider
     * @param AclChecker $aclChecker
     * @param UserContextInterface $userContext
     */
    public function __construct(
        AuthenticatedContextProvider $contextProvider,
        AclChecker $aclChecker,
        private readonly UserContextInterface $userContext
    ) {
        parent::__construct($contextProvider, $aclChecker);
    }

    /**
     * Admin user id of the MCP caller, else of the logged-in admin, or null.
     *
     * @return int|null
     */
    public function adminUserId(): ?int
    {
        $mcpAdminId = parent::adminUserId();
        if ($mcpAdminId !== null) {
            return $mcpAdminId;
        }
        if ($this->userContext->getUserType() !== UserContextInterface::USER_TYPE_ADMIN) {
            return null;
        }
        $id = (int) $this->userContext->getUserId();
        return $id > 0 ? $id : null;
    }
}
