<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Balance;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit\AbstractStoreCreditTool;

class BalanceGet extends AbstractStoreCreditTool
{
    public const TOOL_NAME = 'hyva_store_credit.balance.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_store_credit_balance_get';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Store Credit Balance';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read a customer\'s Hyvä store credit balance (by customer_id or email). With website_id: that '
            . 'website only; without it: every website your role may see. Use hyva_store_credit.history.list for '
            . 'how the balance came about.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withCustomer(Schema::object())->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeStoreCredit(array $arguments): ToolResultInterface
    {
        $customer = $this->locateCustomer($arguments);
        $websiteId = $this->intArg($arguments, 'website_id');
        $adminUserId = $this->adminUserId();
        $balances = [];
        foreach ($this->storeCredit->websites() as $website) {
            if (($websiteId !== null && $website['website_id'] !== $websiteId)
                || !$this->scopeGuard->allowsWebsite($adminUserId, $website['website_id'])) {
                continue;
            }
            $balances[] = $this->storeCredit->getBalance($customer['id'], $website['website_id']) + [
                'website_code' => $website['code'],
                'store_credit_enabled' => $website['enabled'],
            ];
        }
        if ($websiteId !== null && $balances === []) {
            $this->website($websiteId);
            $this->assertWebsiteAllowed($websiteId, false);
        }
        return $this->jsonResult(
            ['customer' => $customer, 'balances' => $balances],
            ['customer_id' => $customer['id']]
        );
    }
}
