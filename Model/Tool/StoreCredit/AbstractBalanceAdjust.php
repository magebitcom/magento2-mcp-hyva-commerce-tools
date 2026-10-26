<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\NumberBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\Exception\LocalizedException;

/**
 * Manual store credit credit/debit, previewed first and idempotent.
 *
 * Hyvä's ledger returns the earlier entry, silently, when an idempotency key is reused, even for a different
 * customer or amount; the tool looks the key up first and tells a replay apart from a key clash.
 */
abstract class AbstractBalanceAdjust extends AbstractStoreCreditTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_StoreCredit::balance_adjust';
    public const IS_WRITE = true;

    /**
     * +1 for credit, -1 for debit.
     */
    protected const DIRECTION = 1;

    /**
     * Key prefixes Hyvä reserves for checkout spends, reverts and credit memo refunds.
     */
    private const RESERVED_KEY_PREFIXES = ['sc-spend-', 'sc-revert-', 'sc-refund-creditmemo-'];

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withCustomer(Schema::object())
            ->number('amount', fn (NumberBuilder $n) => $n->exclusiveMinimum(0)->maximum(1000000)->required()
                ->description('Positive amount in the balance currency (website base currency).'))
            ->string('reason', fn (StringBuilder $s) => $s->minLength(3)->maxLength(255)->required()
                ->description('Internal reason, shown to admins only.'))
            ->string('reason_external', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('Reason shown to the customer in their history and email.'))
            ->boolean('notify_customer', fn (BooleanBuilder $b) => $b->description('Send Hyvä\'s balance change '
                . 'email (if enabled in config). Default false.'))
            ->string('idempotency_key', fn (StringBuilder $s) => $s->pattern('^[A-Za-z0-9._:-]{8,64}$')
                ->description('Repeat-safe key, e.g. your ticket id. Calling again with the same key does not '
                    . 'adjust twice. Default: derived from customer, website, amount, reasons and today\'s date.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Apply after reviewing the preview.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeStoreCredit(array $arguments): ToolResultInterface
    {
        $customer = $this->locateCustomer($arguments);
        $websiteId = $this->intArg($arguments, 'website_id') ?? $customer['website_id'];
        $website = $this->website($websiteId);
        $this->assertWebsiteAllowed($websiteId, true);
        $adminUserId = $this->adminUserId();
        if ($adminUserId === null) {
            return $this->softError('Store credit changes must be attributed to an admin user; this MCP token has '
                . 'none.');
        }
        if (!$website['enabled']) {
            return $this->softError(sprintf(
                'Store credit is disabled on website "%s" (hyva_store_credit/general/enabled), so the customer '
                . 'could neither see nor spend it. Nothing was changed.',
                $website['code']
            ));
        }
        $amount = is_numeric($arguments['amount'] ?? null) ? round((float) $arguments['amount'], 4) : 0.0;
        $reason = trim((string) $this->stringArg($arguments, 'reason'));
        if ($amount <= 0 || $reason === '') {
            return $this->softError('Pass a positive amount and a reason.');
        }
        $delta = static::DIRECTION * $amount;
        $reasonExternal = $this->stringArg($arguments, 'reason_external');
        $notify = $this->boolArg($arguments, 'notify_customer');
        $key = $this->stringArg($arguments, 'idempotency_key')
            ?? $this->derivedKey($customer['id'], $websiteId, $delta, $reason, $reasonExternal, $adminUserId);
        foreach (self::RESERVED_KEY_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return $this->softError(sprintf('Keys starting with "%s" are reserved by Hyvä.', $prefix));
            }
        }

        $existing = $this->storeCredit->findByIdempotencyKey($key);
        if ($existing !== null) {
            if ($existing['customer_id'] !== $customer['id'] || $existing['website_id'] !== $websiteId
                || abs($existing['delta'] - $delta) > 0.00001) {
                // Show the clashing entry only when the caller may see its website.
                $visible = $this->scopeGuard->allowsWebsite($adminUserId, $existing['website_id']);
                return $this->softError(
                    'This idempotency_key was already used for a different adjustment; nothing was changed. Use a '
                    . 'new key.',
                    ['status' => 409] + ($visible ? ['existing_entry' => $existing] : [])
                );
            }
            return $this->jsonResult([
                'replayed' => true,
                'entry' => $existing,
                'note' => 'This adjustment was already made with the same idempotency_key; it was not applied '
                    . 'again. To make a second, separate adjustment, pass a new idempotency_key.',
            ], ['entry_id' => $existing['entry_id'], 'replayed' => true]);
        }

        $balance = $this->storeCredit->getBalance($customer['id'], $websiteId);
        $after = round($balance['amount'] + $delta, 4);
        if ($after < 0) {
            return $this->softError(sprintf(
                'The balance is %s; a debit of %s would take it below zero. Nothing was changed.',
                $balance['amount'],
                $amount
            ), ['balance' => $balance]);
        }
        $affected = [
            'customer' => $customer,
            'website' => $website['code'],
            'currency' => $balance['currency'] ?? $website['currency'],
            'balance_now' => $balance['amount'],
            'delta' => $delta,
            'balance_after' => $after,
            'reason' => $reason,
            'reason_external' => $reasonExternal,
            'notify_customer' => $notify,
            'idempotency_key' => $key,
        ];
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(static::DIRECTION > 0 ? 'credit_store_credit' : 'debit_store_credit', [
                $affected,
            ]);
        }
        try {
            $entry = $this->storeCredit->adjust(
                $customer['id'],
                $websiteId,
                $delta,
                $reason,
                $reasonExternal,
                $key,
                $adminUserId,
                $notify
            );
        } catch (LocalizedException $e) {
            // InsufficientBalanceException when the balance changed since the preview.
            return $this->softError($e->getMessage() . ' Nothing was changed.');
        }
        return $this->jsonResult(
            ['replayed' => false, 'entry' => $entry, 'idempotency_key' => $key],
            ['entry_id' => $entry['entry_id'], 'customer_id' => $customer['id'], 'delta' => $delta]
        );
    }

    /**
     * Key for a call without one: the same request on the same day is applied once.
     *
     * @param int $customerId
     * @param int $websiteId
     * @param float $delta
     * @param string $reason
     * @param string|null $reasonExternal
     * @param int $adminUserId
     * @return string
     */
    private function derivedKey(
        int $customerId,
        int $websiteId,
        float $delta,
        string $reason,
        ?string $reasonExternal,
        int $adminUserId
    ): string {
        $fingerprint = implode('|', [
            $customerId,
            $websiteId,
            sprintf('%.4F', $delta),
            $reason,
            (string) $reasonExternal,
            $adminUserId,
            gmdate('Y-m-d'),
        ]);
        return 'mcp-' . substr(hash('sha256', $fingerprint), 0, 40);
    }
}
