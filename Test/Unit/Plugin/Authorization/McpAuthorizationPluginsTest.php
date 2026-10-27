<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Plugin\Authorization;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Hyva\AdvancedAdminPermissions\Api\AdminUserIdResolverInterface;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Model\Acl\McpCallScope;
use Magebit\McpHyvaCommerceTools\Plugin\Authorization\McpAdminUserIdPlugin;
use Magebit\McpHyvaCommerceTools\Plugin\Authorization\McpRoleLocatorPlugin;
use Magebit\McpHyvaCommerceTools\Plugin\Tool\McpCallScopePlugin;
use Magento\Framework\Authorization\RoleLocatorInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class McpAuthorizationPluginsTest extends TestCase
{
    /**
     * @var AuthenticatedContextProvider
     */
    private AuthenticatedContextProvider $contextProvider;

    /**
     * @var McpCallScope
     */
    private McpCallScope $scope;

    protected function setUp(): void
    {
        $this->contextProvider = new AuthenticatedContextProvider();
        $this->scope = new McpCallScope();
    }

    public function testRoleIsOnlyHandedOverInsideAToolCall(): void
    {
        $plugin = new McpRoleLocatorPlugin($this->caller(), $this->scope);
        $locator = $this->createMock(RoleLocatorInterface::class);

        $this->assertSame('', $plugin->afterGetAclRoleId($locator, ''), 'no MCP context at all');
        $this->authenticate();
        $this->assertSame('', $plugin->afterGetAclRoleId($locator, ''), 'context left over, but no call running');

        $seen = $this->insideToolCall(fn () => $plugin->afterGetAclRoleId($locator, ''));
        $this->assertSame('12', $seen, 'the MCP caller role while the tool runs');
        $this->assertSame('', $plugin->afterGetAclRoleId($locator, ''), 'scope closed again after the call');
        $this->assertSame(
            '3',
            $this->insideToolCall(fn () => $plugin->afterGetAclRoleId($locator, '3')),
            'a role another locator found is never replaced'
        );
    }

    public function testAdvancedPermissionsSeeTheCallerOnlyInsideAToolCall(): void
    {
        $plugin = new McpAdminUserIdPlugin($this->caller(), $this->scope);
        $resolver = $this->createMock(AdminUserIdResolverInterface::class);
        $this->authenticate();

        $this->assertNull($plugin->afterGetUserId($resolver, null));
        $this->assertSame(6, $this->insideToolCall(fn () => $plugin->afterGetUserId($resolver, null)));
        $this->assertSame(9, $this->insideToolCall(fn () => $plugin->afterGetUserId($resolver, 9)));
    }

    public function testScopeClosesWhenTheToolThrows(): void
    {
        $scopePlugin = new McpCallScopePlugin($this->scope);
        $failing = static fn (): ToolResultInterface => throw new \RuntimeException('tool failed');
        $this->expectException(\RuntimeException::class);
        try {
            $scopePlugin->aroundExecute($this->createMock(ToolInterface::class), $failing, []);
        } finally {
            $this->assertFalse($this->scope->isActive());
        }
    }

    /**
     * Run inside McpCallScopePlugin like a real tools/call.
     *
     * @param callable $probe
     * @return mixed
     */
    private function insideToolCall(callable $probe): mixed
    {
        $seen = null;
        (new McpCallScopePlugin($this->scope))->aroundExecute(
            $this->createMock(ToolInterface::class),
            function () use ($probe, &$seen): ToolResultInterface {
                $seen = $probe();
                return $this->createMock(ToolResultInterface::class);
            },
            []
        );
        return $seen;
    }

    /**
     * Authenticate.
     *
     * @return void
     */
    private function authenticate(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getAclRole')->willReturn('12');
        $user->method('getId')->willReturn(6);
        $this->contextProvider->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
    }

    /**
     * @return CallerContext
     */
    private function caller(): CallerContext
    {
        return new CallerContext($this->contextProvider, $this->createMock(AclChecker::class));
    }
}
