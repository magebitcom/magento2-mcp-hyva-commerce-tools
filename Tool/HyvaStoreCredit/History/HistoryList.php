<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\History;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit\AbstractStoreCreditTool;

class HistoryList extends AbstractStoreCreditTool
{
    public const TOOL_NAME = 'hyva_store_credit.history.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_store_credit_history_list';

    private const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2}(:\\d{2})?)?$';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Store Credit History';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List Hyvä store credit ledger entries, newest first: credits, debits, spends at checkout, refunds, '
            . 'with the balance after each, the reason, who made it (admin_user_id / source) and linked order or '
            . 'credit memo. Filter by customer (customer_id or email), website, event_type (ids from '
            . 'hyva_store_credit.status) and created_at range.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withCustomer(Schema::object())
            ->integer('event_type', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('from', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN)
                ->description('created_at from (Y-m-d or Y-m-d H:i:s, UTC).'))
            ->string('to', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN)
                ->description('created_at up to (Y-m-d or Y-m-d H:i:s, UTC).'))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeStoreCredit(array $arguments): ToolResultInterface
    {
        $customerId = null;
        if ($this->intArg($arguments, 'customer_id') !== null || $this->stringArg($arguments, 'email') !== null) {
            $customerId = $this->locateCustomer($arguments)['id'];
        }
        $websiteId = $this->intArg($arguments, 'website_id');
        $scopes = $this->scopeGuard->allowedScopes($this->adminUserId());
        if ($websiteId !== null) {
            $this->website($websiteId);
            $this->assertWebsiteAllowed($websiteId, false);
        } elseif ($scopes['restricted']) {
            return $this->softError('Your admin role is restricted to some websites: pass website_id.', [
                'allowed_website_ids' => $scopes['website_ids'],
            ]);
        }
        $to = $this->stringArg($arguments, 'to');
        if ($to !== null && strlen($to) === 10) {
            $to .= ' 23:59:59';
        }
        $result = $this->storeCredit->history(
            $customerId,
            $websiteId,
            $this->intArg($arguments, 'event_type'),
            $this->stringArg($arguments, 'from'),
            $to,
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
