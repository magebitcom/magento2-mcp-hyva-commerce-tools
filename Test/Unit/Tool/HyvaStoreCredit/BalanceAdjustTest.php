<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaStoreCredit;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\StoreCreditGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Balance\BalanceCredit;
use Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Balance\BalanceDebit;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BalanceAdjustTest extends TestCase
{
    /**
     * @var StoreCreditGatewayInterface&MockObject
     */
    private StoreCreditGatewayInterface $gateway;

    /**
     * @var bool
     */
    private bool $enabled = true;

    /**
     * @var bool
     */
    private bool $scopeAllowsWrite = true;

    /**
     * @var list<int> Websites the admin may see
     */
    private array $readableWebsites = [1, 2];

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(StoreCreditGatewayInterface::class);
        $this->gateway->method('websites')->willReturnCallback(fn (): array => [[
            'website_id' => 1, 'code' => 'base', 'name' => 'Main', 'enabled' => $this->enabled,
            'history_visible' => true, 'email_enabled' => true, 'receive_email_enabled' => true,
            'spend_email_enabled' => true, 'currency' => 'USD',
        ]]);
        $this->gateway->method('getBalance')->willReturn([
            'customer_id' => 1, 'website_id' => 1, 'amount' => 20.0, 'currency' => 'USD', 'updated_at' => null,
        ]);
    }

    public function testPreviewWithoutConfirmWritesNothing(): void
    {
        $this->gateway->expects($this->never())->method('adjust');
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'goodwill']));
        $this->assertTrue($payload['confirmation_required']);
        /** @var array{affected: list<array{balance_after: float, idempotency_key: string}>} $payload */
        $this->assertEquals(25, $payload['affected'][0]['balance_after']);
        $this->assertStringStartsWith('mcp-', $payload['affected'][0]['idempotency_key']);
    }

    public function testSameKeySameAdjustmentIsReplayed(): void
    {
        $this->gateway->method('findByIdempotencyKey')->willReturn($this->entry(1, 1, 5.0));
        $this->gateway->expects($this->never())->method('adjust');
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'goodwill', 'idempotency_key' => 'ticket-1234', 'confirm' => true]));
        $this->assertTrue($payload['replayed']);
    }

    public function testSameKeyDifferentAdjustmentIsRefused(): void
    {
        $this->gateway->method('findByIdempotencyKey')->willReturn($this->entry(1, 1, 5.0));
        $this->gateway->expects($this->never())->method('adjust');
        $result = $this->tool(BalanceDebit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'correction', 'idempotency_key' => 'ticket-1234', 'confirm' => true]);
        $this->assertTrue($result->isError());
        $this->assertSame(409, $this->payload($result)['status']);
    }

    public function testDebitBelowZeroIsRefused(): void
    {
        $this->gateway->expects($this->never())->method('adjust');
        $result = $this->tool(BalanceDebit::class)->execute(['customer_id' => 1, 'amount' => 25,
            'reason' => 'correction', 'confirm' => true]);
        $this->assertTrue($result->isError());
    }

    public function testDisabledWebsiteIsRefused(): void
    {
        $this->enabled = false;
        $this->gateway->expects($this->never())->method('adjust');
        $result = $this->tool(BalanceCredit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'goodwill', 'confirm' => true]);
        $this->assertTrue($result->isError());
    }

    public function testScopeRestrictedAdminIsRefused(): void
    {
        $this->scopeAllowsWrite = false;
        $this->gateway->expects($this->never())->method('adjust');
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'goodwill', 'confirm' => true]));
        $this->assertSame(403, $payload['status']);
    }

    public function testCustomerOutsideTheReadScopeIsNotRevealed(): void
    {
        $this->readableWebsites = [2];
        $this->gateway->expects($this->never())->method('getBalance');
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'goodwill']));
        $this->assertSame(403, $payload['status']);
        $this->assertArrayNotHasKey('affected', $payload);
    }

    public function testKeyClashHidesAnEntryFromAnotherScope(): void
    {
        $this->gateway->method('findByIdempotencyKey')->willReturn($this->entry(9, 2, 5.0));
        $args = ['customer_id' => 1, 'amount' => 5, 'reason' => 'goodwill', 'idempotency_key' => 'ticket-1234',
            'confirm' => true];
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute($args));
        $this->assertSame(409, $payload['status']);
        $this->assertArrayHasKey('existing_entry', $payload, 'website 2 is visible');

        $this->readableWebsites = [1];
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute($args));
        $this->assertSame(409, $payload['status']);
        $this->assertArrayNotHasKey('existing_entry', $payload, 'website 2 is outside the scope');
    }

    public function testConfirmedCreditIsWrittenWithTheAdminAndKey(): void
    {
        $this->gateway->expects($this->once())->method('adjust')
            ->with(1, 1, 5.0, 'goodwill', null, 'ticket-1234', 6, false)
            ->willReturn($this->entry(1, 1, 5.0));
        $payload = $this->payload($this->tool(BalanceCredit::class)->execute(['customer_id' => 1, 'amount' => 5,
            'reason' => 'goodwill', 'idempotency_key' => 'ticket-1234', 'confirm' => true]));
        $this->assertFalse($payload['replayed']);
    }

    /**
     * @param class-string<BalanceCredit|BalanceDebit> $class
     * @return BalanceCredit|BalanceDebit
     */
    private function tool(string $class): BalanceCredit|BalanceDebit
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(1);
        $customer->method('getWebsiteId')->willReturn(1);
        $customer->method('getEmail')->willReturn('c@example.com');
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturn($customer);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $context = new AuthenticatedContextProvider();
        $context->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $caller = new CallerContext($context, $this->createMock(AclChecker::class));
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $scopes = $this->createMock(AdminScopeGatewayInterface::class);
        $scopes->method('allowsWebsiteForWrite')->willReturnCallback(fn (): bool => $this->scopeAllowsWrite);
        $scopes->method('allowsWebsite')->willReturnCallback(
            fn (int $adminUserId, int $websiteId): bool => in_array($websiteId, $this->readableWebsites, true)
        );
        return new $class($features, $this->gateway, $customers, $caller, new AdminScopeGuard($modules, $scopes));
    }

    /**
     * @param int $customerId
     * @param int $websiteId
     * @param float $delta
     * @return array<string, mixed>
     */
    private function entry(int $customerId, int $websiteId, float $delta): array
    {
        return [
            'entry_id' => 7, 'customer_id' => $customerId, 'website_id' => $websiteId, 'event' => 'Credit',
            'delta' => $delta, 'balance_after' => 25.0, 'currency' => 'USD', 'reason' => 'goodwill',
            'reason_external' => '', 'source' => 'admin', 'idempotency_key' => 'ticket-1234', 'admin_user_id' => 6,
            'api_user' => '', 'order_id' => null, 'creditmemo_id' => null, 'customer_notified' => false,
            'created_at' => '2026-10-16 12:00:00',
        ];
    }

    /**
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload, $text);
        return $payload;
    }
}
