<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Auth;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Model\Auth\AdminSessionCallerContext;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Authorization\Model\UserContextInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * Dashboard widgets apply the logged-in admin's website/store view restriction (finding: unscoped widgets).
 */
class AdminSessionCallerContextTest extends TestCase
{
    public function testTheLoggedInAdminIsTheCallerInTheAdmin(): void
    {
        $caller = new AdminSessionCallerContext(
            new AuthenticatedContextProvider(),
            $this->createMock(AclChecker::class),
            $this->userContext(UserContextInterface::USER_TYPE_ADMIN, 9)
        );

        $this->assertSame(9, $caller->adminUserId());
    }

    public function testOtherUserTypesAreNoAdmin(): void
    {
        foreach ([UserContextInterface::USER_TYPE_CUSTOMER, UserContextInterface::USER_TYPE_GUEST] as $type) {
            $caller = new AdminSessionCallerContext(
                new AuthenticatedContextProvider(),
                $this->createMock(AclChecker::class),
                $this->userContext($type, 9)
            );
            $this->assertNull($caller->adminUserId());
        }
        $caller = new AdminSessionCallerContext(
            new AuthenticatedContextProvider(),
            $this->createMock(AclChecker::class),
            $this->userContext(UserContextInterface::USER_TYPE_ADMIN, null)
        );
        $this->assertNull($caller->adminUserId());
    }

    public function testAnMcpCallerWins(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $provider = new AuthenticatedContextProvider();
        $provider->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $caller = new AdminSessionCallerContext(
            $provider,
            $this->createMock(AclChecker::class),
            $this->userContext(UserContextInterface::USER_TYPE_ADMIN, 9)
        );

        $this->assertSame(6, $caller->adminUserId());
    }

    public function testTheAdminAreaGivesTheScopeGuardThisContext(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/adminhtml/di.xml');
        $this->assertNotFalse($xml);
        $arguments = $xml->xpath(sprintf(
            '//type[@name="%s"]/arguments/argument[@name="callerContext"]',
            StoreScopeGuard::class
        )) ?: [];
        $this->assertCount(1, $arguments);
        $this->assertSame(AdminSessionCallerContext::class, trim((string) $arguments[0]));
    }

    /**
     * User context of the given type.
     *
     * @param int $type
     * @param int|null $userId
     * @return UserContextInterface
     */
    private function userContext(int $type, ?int $userId): UserContextInterface
    {
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserType')->willReturn($type);
        $userContext->method('getUserId')->willReturn($userId);
        return $userContext;
    }
}
