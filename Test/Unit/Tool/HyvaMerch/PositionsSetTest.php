<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMerch;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Merchandising\CategoryPositions;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMerch\Category\PositionsSet;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PositionsSetTest extends TestCase
{
    /**
     * @var CategoryPositions&MockObject
     */
    private CategoryPositions $positions;

    protected function setUp(): void
    {
        $this->positions = $this->createMock(CategoryPositions::class);
        $this->positions->method('plan')->willReturn([
            'before' => [], 'after' => [], 'moved' => 2, 'positions' => [10 => 0, 20 => 1],
        ]);
        $this->positions->method('notes')->willReturn([]);
    }

    public function testAScopeRestrictedRoleIsRefused(): void
    {
        $this->positions->expects($this->never())->method('save');
        $result = $this->tool(true)->execute(['category_id' => 6, 'top_skus' => ['A'], 'confirm' => true]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testWithoutConfirmItIsAPreview(): void
    {
        $this->positions->expects($this->never())->method('save');
        $result = $this->tool(false)->execute(['category_id' => 6, 'top_skus' => ['A']]);
        $this->assertStringContainsString('confirmation_required', json_encode($result->getContent()) ?: '');
    }

    public function testConfirmedReorderIsSaved(): void
    {
        $this->positions->expects($this->once())->method('save')->with(6, [10 => 0, 20 => 1]);
        $result = $this->tool(false)->execute(['category_id' => 6, 'top_skus' => ['A'], 'confirm' => true]);
        $this->assertFalse($result->isError());
    }

    /**
     * @param bool $restricted
     * @return PositionsSet
     */
    private function tool(bool $restricted): PositionsSet
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $scopes = $this->createMock(AdminScopeGatewayInterface::class);
        $scopes->method('allowedScopes')->willReturn(['restricted' => $restricted, 'website_ids' => [1],
            'store_ids' => [1]]);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $context = new AuthenticatedContextProvider();
        $context->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $caller = new CallerContext($context, $this->createMock(AclChecker::class));
        return new PositionsSet($features, $this->positions, new AdminScopeGuard($modules, $scopes), $caller);
    }
}
