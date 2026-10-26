<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

use Magento\Framework\Exception\LocalizedException;

/**
 * Hyvä Store Credit access. Only the adapter references Hyva\StoreCredit classes; tools reach it through a proxy,
 * after the store_credit feature check.
 *
 * Hyvä's services check no ACL and ignore the enabled flag; the tools do both.
 *
 * @phpstan-type LedgerRow array{entry_id: int, customer_id: int, website_id: int, event: string, delta: float,
 *     balance_after: float|null, currency: string|null, reason: string, reason_external: string, source: string,
 *     idempotency_key: string, admin_user_id: int|null, api_user: string, order_id: int|null,
 *     creditmemo_id: int|null, customer_notified: bool, created_at: string}
 * @phpstan-type WebsiteStatus array{website_id: int, code: string, name: string, enabled: bool,
 *     history_visible: bool, email_enabled: bool, receive_email_enabled: bool, spend_email_enabled: bool,
 *     currency: string}
 */
interface StoreCreditGatewayInterface
{
    /**
     * Store credit settings per website (the enabled flag is website-scoped)
     *
     * @return array
     * @phpstan-return list<WebsiteStatus>
     */
    public function websites(): array;

    /**
     * Current balance of a customer on a website (0 when there is none)
     *
     * @param int $customerId
     * @param int $websiteId
     * @return array
     * @phpstan-return array{customer_id: int, website_id: int, amount: float, currency: string|null,
     *     updated_at: string|null}
     * @throws LocalizedException
     */
    public function getBalance(int $customerId, int $websiteId): array;

    /**
     * Ledger entries, newest first
     *
     * @param int|null $customerId
     * @param int|null $websiteId
     * @param int|null $eventType
     * @param string|null $from created_at lower bound (Y-m-d or Y-m-d H:i:s)
     * @param string|null $to created_at upper bound
     * @param int $page
     * @param int $pageSize
     * @return array
     * @phpstan-return array{total: int, items: list<LedgerRow>}
     */
    public function history(
        ?int $customerId,
        ?int $websiteId,
        ?int $eventType,
        ?string $from,
        ?string $to,
        int $page,
        int $pageSize
    ): array;

    /**
     * Ledger entry already written with this idempotency key, or null
     *
     * @param string $idempotencyKey
     * @return array|null
     * @phpstan-return LedgerRow|null
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array;

    /**
     * Append a manual adjustment (credit when delta > 0, debit when delta < 0), attributed to the admin user
     *
     * @param int $customerId
     * @param int $websiteId
     * @param float $delta
     * @param string $reason Internal reason (admin only)
     * @param string|null $reasonExternal Reason shown to the customer
     * @param string $idempotencyKey
     * @param int|null $adminUserId
     * @param bool $notifyCustomer
     * @return array
     * @phpstan-return LedgerRow
     * @throws LocalizedException Including Hyvä's InsufficientBalanceException.
     */
    public function adjust(
        int $customerId,
        int $websiteId,
        float $delta,
        string $reason,
        ?string $reasonExternal,
        string $idempotencyKey,
        ?int $adminUserId,
        bool $notifyCustomer
    ): array;

    /**
     * Event type labels by id
     *
     * @return array<int, string>
     */
    public function eventTypes(): array;
}
