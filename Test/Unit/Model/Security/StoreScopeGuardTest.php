<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security;

use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The store view rules of Hyvä Advanced Admin Permissions for store-linked entities.
 */
class StoreScopeGuardTest extends TestCase
{
    use StoreScopeStub;

    public function testAnUnrestrictedCallerPassesEveryCheck(): void
    {
        $guard = $this->storeScope(null);
        $this->assertNull($guard->visibleStoreIds());
        $this->assertTrue($guard->allowsRead([2]));
        $this->assertTrue($guard->allowsWrite([0]));
        $this->assertTrue($guard->allowsAssignment([2], [1]));
        $guard->assertUnrestricted('Email templates');
    }

    public function testWithoutTheScopeModuleOrAnMcpAdminNothingIsRestricted(): void
    {
        $gateway = $this->createMock(AdminScopeGatewayInterface::class);
        $gateway->expects($this->never())->method('allowedScopes');
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(false);
        $caller = $this->createMock(CallerContext::class);
        $caller->method('adminUserId')->willReturn(7);
        $guard = new StoreScopeGuard(
            new AdminScopeGuard($modules, $gateway),
            $caller,
            $this->createMock(StoreManagerInterface::class)
        );
        $this->assertNull($guard->restrictedStoreIds());
        $this->assertTrue($guard->allowsWrite([2]));
    }

    public function testReadAllowsOwnStoreViewsAllStoreViewsAndUnassignedEntities(): void
    {
        $guard = $this->storeScope([1]);
        $this->assertSame([0, 1], $guard->visibleStoreIds());
        $this->assertTrue($guard->allowsRead([1]));
        $this->assertTrue($guard->allowsRead([0]));
        $this->assertTrue($guard->allowsRead([1, 2]));
        $this->assertTrue($guard->allowsRead([]));
        $this->assertFalse($guard->allowsRead([2]));
    }

    public function testWriteNeedsEveryAssignedStoreView(): void
    {
        $guard = $this->storeScope([1]);
        $this->assertTrue($guard->allowsWrite([1]));
        $this->assertFalse($guard->allowsWrite([1, 2]));
        $this->assertFalse($guard->allowsWrite([2]));
        // "All Store Views" is inherited by store view 2 as well.
        $this->assertFalse($guard->allowsWrite([0]));
        $this->assertTrue($this->storeScope([1, 2])->allowsWrite([0]));
    }

    public function testAssignmentMayNotAddOrDropForeignStoreViews(): void
    {
        $guard = $this->storeScope([1]);
        $this->assertTrue($guard->allowsAssignment([], [1]));
        $this->assertFalse($guard->allowsAssignment([], [0]));
        $this->assertFalse($guard->allowsAssignment([], [1, 2]));
        $this->assertFalse($guard->allowsAssignment([1, 2], [1]));
    }

    public function testRefusalsAreAuthorizationErrorsNamingTheEntity(): void
    {
        $guard = $this->storeScope([1]);
        try {
            $guard->assertReadable('CMS page #5', [2]);
            $this->fail('An out-of-scope page must be refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('CMS page #5', $e->getMessage());
        }
        $this->expectException(AuthorizationException::class);
        $guard->assertUnrestricted('Linked product groups');
    }

    public function testResolveStoreIdKeepsTheValueForAnUnrestrictedCaller(): void
    {
        $guard = $this->storeScope(null);
        $this->assertNull($guard->resolveStoreId(null));
        $this->assertSame(0, $guard->resolveStoreId(0));
        $this->assertSame(2, $guard->resolveStoreId(2));
    }

    public function testResolveStoreIdPicksTheFirstReadableStoreViewForARestrictedCaller(): void
    {
        $guard = $this->storeScope([2, 1]);
        $this->assertSame(2, $guard->resolveStoreId(null));
        $this->assertSame(1, $guard->resolveStoreId(1));
    }

    public function testResolveStoreIdRefusesZeroAndForeignStoreViewsForARestrictedCaller(): void
    {
        $guard = $this->storeScope([1]);
        foreach ([0, 2] as $storeId) {
            try {
                $guard->resolveStoreId($storeId);
                $this->fail('Store view ' . $storeId . ' must be refused.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
