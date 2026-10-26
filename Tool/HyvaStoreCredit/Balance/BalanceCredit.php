<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Balance;

use Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit\AbstractBalanceAdjust;

class BalanceCredit extends AbstractBalanceAdjust
{
    public const TOOL_NAME = 'hyva_store_credit.balance.credit';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_store_credit_balance_credit';
    protected const DIRECTION = 1;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Credit Store Credit';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add store credit to a customer\'s Hyvä balance (a goodwill credit, a compensation, a manual top-up). '
            . 'Recorded in the ledger as a Credit Adjustment under your admin user. Without confirm=true you get a '
            . 'preview with the balance before and after. Repeat-safe: '
            . 'the same idempotency_key never applies twice.';
    }
}
