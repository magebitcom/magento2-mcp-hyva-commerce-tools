<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaStoreCredit;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\StoreCreditGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\History\HistoryList;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HistoryListTest extends TestCase
{
    use RefusalAssertTrait;
    use ToolPayloadTrait;

    /**
     * @var StoreCreditGatewayInterface&MockObject
     */
    private StoreCreditGatewayInterface $gateway;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(StoreCreditGatewayInterface::class);
    }

    public function testAWebsiteRestrictedRoleWithoutWebsiteIdIsRefusedWithItsWebsites(): void
    {
        $this->gateway->expects($this->never())->method('history');
        $tool = $this->tool(true);

        $refusal = $this->assertRefused(fn () => $tool->execute([]), 'scope');
        $this->assertSame('Your admin role is restricted to some websites: pass website_id.', $refusal->getMessage());
        $this->assertSame([1], $refusal->getData()['allowed_website_ids'] ?? null);
    }

    public function testAnUnrestrictedRoleListsEveryWebsite(): void
    {
        $this->gateway->expects($this->once())->method('history')->willReturn(['total' => 0, 'items' => []]);

        $this->assertSame(0, $this->payloadAt($this->tool(false)->execute([]), 'total'));
    }

    /**
     * @param bool $restricted
     * @return HistoryList
     */
    private function tool(bool $restricted): HistoryList
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $context = new AuthenticatedContextProvider();
        $context->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $scopes = $this->createMock(AdminScopeGatewayInterface::class);
        $scopes->method('allowedScopes')->willReturn(['restricted' => $restricted, 'website_ids' => [1],
            'store_ids' => [1]]);
        return new HistoryList(
            $features,
            $this->gateway,
            $this->createMock(CustomerRepositoryInterface::class),
            new CallerContext($context, $this->createMock(AclChecker::class)),
            new AdminScopeGuard($modules, $scopes)
        );
    }
}
