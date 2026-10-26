<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Balance;

use Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit\AbstractBalanceAdjust;

class BalanceDebit extends AbstractBalanceAdjust
{
    public const TOOL_NAME = 'hyva_store_credit.balance.debit';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_store_credit_balance_debit';
    protected const DIRECTION = -1;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Debit Store Credit';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Take store credit off a customer\'s Hyvä balance (a correction, an expired goodwill credit). Refused '
            . 'when the balance would go below zero. Recorded as a Debit Adjustment under your admin user. Without '
            . 'confirm=true you get a preview with the balance before and after. Repeat-safe: '
            . 'the same idempotency_key never applies twice.';
    }
}
