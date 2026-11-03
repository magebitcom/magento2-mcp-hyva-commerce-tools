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

    public function testRestrictedAdminUserIdNamesOnlyARestrictedCaller(): void
    {
        $this->assertNull($this->storeScope(null)->restrictedAdminUserId());
        $this->assertSame(7, $this->storeScope([2])->restrictedAdminUserId());
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

    public function testAChangeMayMoveAnAllStoreViewsEntityIntoOwnStoreViewsOnly(): void
    {
        $guard = $this->storeScope([1]);
        $guard->assertChangeable('CMS page #5', [0], [1]);
        $guard->assertChangeable('CMS page #5', [1], null);
        foreach ([[[0], null], [[0], [0]], [[0], [1, 2]], [[2], [1]]] as [$current, $new]) {
            try {
                $guard->assertChangeable('CMS page #5', $current, $new);
                $this->fail('The change must be refused.');
            } catch (AuthorizationException $e) {
                $this->assertStringStartsWith(StoreScopeGuard::RESTRICTED, $e->getMessage());
            }
        }
    }

    public function testAnEmptyAssignmentIsJudgedWhereTheEntityIs(): void
    {
        // F1: [] enters no store view; it would only drop store view 0 and take the entity offline everywhere.
        $guard = $this->storeScope([1]);
        $guard->assertChangeable('CMS page #5', [1], []);
        try {
            $guard->assertChangeable('CMS page #5', [0], []);
            $this->fail('Emptying an All Store Views assignment must be refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('may not change CMS page #5', $e->getMessage());
        }
        $this->storeScope(null)->assertChangeable('CMS page #5', [0], []);
    }

    public function testOnlyAMoveOutOfStoreViewsTheRoleMayNotChangeIsAllowedByTheMoveAlone(): void
    {
        $guard = $this->storeScope([1]);
        $this->assertTrue($guard->changesOnlyAfterReassignment([0], [1]));
        $this->assertFalse($guard->changesOnlyAfterReassignment([1], [1]));
        $this->assertFalse($guard->changesOnlyAfterReassignment([0], null));
        $this->assertFalse($guard->changesOnlyAfterReassignment([0], []));
        $this->assertFalse($this->storeScope(null)->changesOnlyAfterReassignment([0], [1]));
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

    public function testRefusalsShareOneOpeningAndCountStoreViews(): void
    {
        $guard = $this->storeScope([1]);
        $messages = [];
        foreach ([
            fn () => $guard->assertReadable('CMS page #5', [2]),
            fn () => $guard->assertWritable('CMS page #6', [1, 2]),
            fn () => $guard->assertWritable('CMS block #7', [0]),
            fn () => $guard->assertAssignable('CMS page #8', [], [2]),
            fn () => $guard->assertUnrestricted('email templates'),
        ] as $call) {
            try {
                $call();
                $this->fail('The call must be refused.');
            } catch (AuthorizationException $e) {
                $messages[] = $e->getMessage();
            }
        }
        foreach ($messages as $message) {
            $this->assertStringStartsWith(StoreScopeGuard::RESTRICTED, $message);
        }
        $this->assertStringContainsString('CMS page #5 (store view 2) is outside it', $messages[0]);
        $this->assertStringContainsString('assigned to store views 1, 2.', $messages[1]);
        $this->assertSame(
            StoreScopeGuard::RESTRICTED . ' and may not change CMS block #7: it is assigned to store view 0 '
            . '("All Store Views"), which needs every store view. Only content assigned to your own store views can '
            . 'be changed.',
            $messages[2]
        );
        $this->assertStringContainsString('assign CMS page #8 to store view 2: store view 2 is', $messages[3]);
        $this->assertStringContainsString('may not change email templates: they apply', $messages[4]);
    }

    /**
     * T-77: a refused change names "All Store Views" once, whatever the entity is assigned to.
     */
    public function testARefusedChangeNamesAllStoreViewsOnce(): void
    {
        $guard = $this->storeScope([1]);
        foreach ([[0], [0, 1], [1, 2]] as $storeIds) {
            try {
                $guard->assertWritable('CMS block #9', $storeIds);
                $this->fail('The change must be refused.');
            } catch (AuthorizationException $e) {
                $this->assertSame(1, substr_count($e->getMessage(), StoreScopeGuard::RESTRICTED));
                $this->assertSame(1, substr_count($e->getMessage(), 'All Store Views'));
                $this->assertSame(1, substr_count($e->getMessage(), 'needs every store view'));
            }
        }
    }

    /**
     * @return array<string, array{0: list<int>, 1: list<int>, 2: string}>
     */
    public static function assignmentRefusalProvider(): array
    {
        $opening = StoreScopeGuard::RESTRICTED . ' and may not assign CMS page #22 to ';
        return [
            'all store views to one foreign' => [[0], [2],
                $opening . 'store view 2: store view 2 is outside your role.'],
            'all store views to all store views' => [[0], [0],
                $opening . 'store view 0 ("All Store Views"): it needs every store view.'],
            'own to all store views and own' => [[1], [0, 1],
                $opening . 'store views 0, 1: store view 0 ("All Store Views") needs every store view.'],
            'own to own and foreign' => [[1], [1, 2],
                $opening . 'store views 1, 2: store view 2 is outside your role.'],
            'shared to own only' => [[1, 2], [1],
                $opening . 'store view 1: that removes it from store view 2, which is outside your role.'],
        ];
    }

    /**
     * T-75: a refused move names only the rule it broke, and "All Store Views" once.
     *
     * @dataProvider assignmentRefusalProvider
     * @param list<int> $current
     * @param list<int> $new
     * @param string $message
     */
    public function testARefusedMoveNamesOnlyTheRuleItBroke(array $current, array $new, string $message): void
    {
        try {
            $this->storeScope([1])->assertAssignable('CMS page #22', $current, $new);
            $this->fail('The move must be refused.');
        } catch (AuthorizationException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(1, substr_count($e->getMessage(), StoreScopeGuard::RESTRICTED));
            $this->assertLessThanOrEqual(1, substr_count($e->getMessage(), 'All Store Views'));
        }
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
