<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit\AbstractStoreCreditTool;

class Status extends AbstractStoreCreditTool
{
    public const TOOL_NAME = 'hyva_store_credit.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_store_credit_status';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Hyvä Store Credit Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Show whether Hyvä Store Credit is enabled per website (customers can only use credit where it is), '
            . 'the balance currency, whether customers see their history and get balance emails, the ledger event '
            . 'types, and which websites your admin role may see and change.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeStoreCredit(array $arguments): ToolResultInterface
    {
        $adminUserId = $this->adminUserId();
        $websites = [];
        foreach ($this->storeCredit->websites() as $website) {
            $websites[] = $website + [
                'you_may_view' => $this->scopeGuard->allowsWebsite($adminUserId, $website['website_id']),
                'you_may_adjust' => $this->scopeGuard->allowsWebsiteForWrite($adminUserId, $website['website_id']),
            ];
        }
        $enabled = count(array_filter($websites, static fn (array $w): bool => $w['enabled']));
        return $this->jsonResult([
            'websites' => $websites,
            'event_types' => $this->storeCredit->eventTypes(),
            'note' => $enabled === 0
                ? 'Store credit is disabled on every website (hyva_store_credit/general/enabled), so customers '
                    . 'cannot see or spend it. Balances can still be read; adjustments are refused until it is on.'
                : null,
        ], ['enabled_websites' => $enabled]);
    }
}
