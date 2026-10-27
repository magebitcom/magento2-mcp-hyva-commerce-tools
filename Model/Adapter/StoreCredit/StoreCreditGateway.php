<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\StoreCredit;

use Magento\Framework\Exception\LocalizedException;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\StoreCredit\Api\BalanceManagementInterface;
use Hyva\StoreCredit\Api\Data\LedgerEntryInterface;
use Hyva\StoreCredit\Api\HistoryRepositoryInterface;
use Hyva\StoreCredit\Model\Config as StoreCreditConfig;
use Hyva\StoreCredit\Model\Ledger\EventType;
use Hyva\StoreCredit\Model\Ledger\LedgerEntryBuilderFactory;
use Hyva\StoreCredit\Model\Ledger\LedgerWriter;
use Hyva\StoreCredit\Model\Ledger\Source;
use Magebit\McpHyvaCommerceTools\Api\Adapter\StoreCreditGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * Adapter over Hyvä Store Credit 1.0.x.
 *
 * Adjustments are built with LedgerEntryBuilder and written with LedgerWriter (as Hyvä's admin BalanceAdjuster
 * does), because BalanceManagementInterface cannot record the admin user or notify the customer, and the admin
 * adjuster reads the admin id from the backend session, which an MCP request does not have.
 *
 * @phpstan-import-type LedgerRow from StoreCreditGatewayInterface
 */
class StoreCreditGateway implements StoreCreditGatewayInterface
{
    /**
     * @param OptionalServiceFactory $optionalServices
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function websites(): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $id = Cast::int($website->getId());
            $websites[] = [
                'website_id' => $id,
                'code' => Cast::string($website->getCode()),
                'name' => Cast::string($website->getName()),
                'enabled' => $this->config()->isEnabled($id),
                'history_visible' => $this->config()->isHistoryVisible($id),
                'email_enabled' => $this->config()->isEmailEnabled($id),
                'receive_email_enabled' => $this->config()->isReceiveEmailEnabled($id),
                'spend_email_enabled' => $this->config()->isSpendEmailEnabled($id),
                'currency' => $website instanceof Website ? Cast::string($website->getBaseCurrencyCode()) : '',
            ];
        }
        return $websites;
    }

    /**
     * @inheritDoc
     */
    public function getBalance(int $customerId, int $websiteId): array
    {
        $balance = $this->balanceManagement()->getBalance($customerId, $websiteId);
        return [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'amount' => $balance->getAmount(),
            'currency' => $balance->getBaseCurrencyCode(),
            'updated_at' => $balance->getUpdatedAt(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function history(
        ?int $customerId,
        ?int $websiteId,
        ?int $eventType,
        ?string $from,
        ?string $to,
        int $page,
        int $pageSize
    ): array {
        $filters = [
            'customer_id' => [$customerId, 'eq'],
            'website_id' => [$websiteId, 'eq'],
            'event_type' => [$eventType, 'eq'],
        ];
        foreach ($filters as $field => [$value, $condition]) {
            if ($value !== null) {
                $this->searchCriteriaBuilder->addFilter($field, $value, $condition);
            }
        }
        if ($from !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $from, 'gteq');
        }
        if ($to !== null) {
            $this->searchCriteriaBuilder->addFilter('created_at', $to, 'lteq');
        }
        $criteria = $this->searchCriteriaBuilder
            ->addSortOrder($this->sortOrderBuilder->setField('entry_id')->setDescendingDirection()->create())
            ->setCurrentPage($page)
            ->setPageSize($pageSize)
            ->create();
        $result = $this->historyRepository()->getList($criteria);
        $items = [];
        foreach ($result->getItems() as $entry) {
            if ($entry instanceof LedgerEntryInterface) {
                $items[] = $this->toRow($entry);
            }
        }
        return ['total' => $result->getTotalCount(), 'items' => $items];
    }

    /**
     * @inheritDoc
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('idempotency_key', $idempotencyKey)
            ->setPageSize(1)
            ->create();
        foreach ($this->historyRepository()->getList($criteria)->getItems() as $entry) {
            if ($entry instanceof LedgerEntryInterface) {
                return $this->toRow($entry);
            }
        }
        return null;
    }

    /**
     * @inheritDoc
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
    ): array {
        $entry = $this->entryBuilderFactory()->create()
            ->withCustomer($customerId, $websiteId)
            ->withEvent($delta > 0 ? EventType::CREDIT_ADJUST : EventType::DEBIT_ADJUST, $delta)
            ->withReason($reason, $reasonExternal)
            ->withSource(Source::ADMIN)
            ->withIdempotencyKey($idempotencyKey)
            ->withAdminUserId($adminUserId)
            ->withCustomerNotified($notifyCustomer)
            ->build();
        $written = $this->toRow($this->ledgerWriter()->append($entry));
        // The appended entry lacks DB defaults such as created_at; read it back.
        return $this->findByIdempotencyKey($idempotencyKey) ?? $written;
    }

    /**
     * @inheritDoc
     */
    public function eventTypes(): array
    {
        return EventType::getLabels();
    }

    /**
     * Ledger entry as a tool row.
     *
     * @param LedgerEntryInterface $entry
     * @return array
     * @phpstan-return LedgerRow
     */
    private function toRow(LedgerEntryInterface $entry): array
    {
        return [
            'entry_id' => (int) $entry->getEntryId(),
            'customer_id' => $entry->getCustomerId(),
            'website_id' => $entry->getWebsiteId(),
            'event' => EventType::getLabel($entry->getEventType()),
            'delta' => $entry->getDelta(),
            'balance_after' => $entry->getBalanceAfter(),
            'currency' => $entry->getBaseCurrencyCode(),
            'reason' => (string) $entry->getReason(),
            'reason_external' => (string) $entry->getReasonExternal(),
            'source' => (string) $entry->getSource(),
            'idempotency_key' => (string) $entry->getIdempotencyKey(),
            'admin_user_id' => $entry->getAdminUserId(),
            'api_user' => (string) $entry->getApiUser(),
            'order_id' => $entry->getOrderId(),
            'creditmemo_id' => $entry->getCreditmemoId(),
            'customer_notified' => $entry->getIsCustomerNotified(),
            'created_at' => (string) $entry->getCreatedAt(),
        ];
    }

    /**
     * StoreCreditConfig from the optional package, created on first use.
     *
     * @return StoreCreditConfig
     * @throws LocalizedException When the package is not installed.
     */
    private function config(): StoreCreditConfig
    {
        return $this->optionalServices->get(StoreCreditConfig::class);
    }

    /**
     * BalanceManagementInterface from the optional package, created on first use.
     *
     * @return BalanceManagementInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function balanceManagement(): BalanceManagementInterface
    {
        return $this->optionalServices->get(BalanceManagementInterface::class);
    }

    /**
     * HistoryRepositoryInterface from the optional package, created on first use.
     *
     * @return HistoryRepositoryInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function historyRepository(): HistoryRepositoryInterface
    {
        return $this->optionalServices->get(HistoryRepositoryInterface::class);
    }

    /**
     * LedgerEntryBuilderFactory from the optional package, created on first use.
     *
     * @return LedgerEntryBuilderFactory
     * @throws LocalizedException When the package is not installed.
     */
    private function entryBuilderFactory(): LedgerEntryBuilderFactory
    {
        return $this->optionalServices->get(LedgerEntryBuilderFactory::class);
    }

    /**
     * LedgerWriter from the optional package, created on first use.
     *
     * @return LedgerWriter
     * @throws LocalizedException When the package is not installed.
     */
    private function ledgerWriter(): LedgerWriter
    {
        return $this->optionalServices->get(LedgerWriter::class);
    }
}
