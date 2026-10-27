<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\StoreCredit;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\McpHyvaCommerceTools\Api\Adapter\StoreCreditGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Base for Hyvä Store Credit tools.
 *
 * Hyvä's store credit services check no permissions and no website scope; tools are listed with
 * Hyva_StoreCredit::* resources and apply the Advanced Admin Permissions website restriction themselves.
 *
 * @phpstan-import-type WebsiteStatus from StoreCreditGatewayInterface
 */
abstract class AbstractStoreCreditTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_StoreCredit::balance_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param StoreCreditGatewayInterface $storeCredit
     * @param CustomerRepositoryInterface $customerRepository
     * @param CallerContext $callerContext
     * @param AdminScopeGuard $scopeGuard
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly StoreCreditGatewayInterface $storeCredit,
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly CallerContext $callerContext,
        protected readonly AdminScopeGuard $scopeGuard
    ) {
    }

    /**
     * Hyvä resource the tool is listed with (customer access while the module is disabled).
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::STORE_CREDIT)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Magento_Customer::customer';
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::STORE_CREDIT)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::STORE_CREDIT)
            );
        }
        return $this->executeStoreCredit($arguments);
    }

    /**
     * Tool body once Store Credit is available.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeStoreCredit(array $arguments): ToolResultInterface;

    /**
     * Add customer_id / email / website_id inputs.
     *
     * @param ObjectBuilder $schema
     * @return ObjectBuilder
     */
    protected function withCustomer(ObjectBuilder $schema): ObjectBuilder
    {
        return $schema
            ->integer('customer_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('email', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('Customer email, instead of customer_id.'))
            ->integer('website_id', fn (IntegerBuilder $i) => $i->minimum(1)
                ->description('Website of the balance (store credit is per website). Default: the customer\'s '
                    . 'website.'));
    }

    /**
     * Customer from customer_id or email, when the caller's website scope includes the customer's website.
     *
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @throws AuthorizationException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array{id: int, email: string, name: string, website_id: int}
     */
    protected function locateCustomer(array $arguments): array
    {
        $id = $this->intArg($arguments, 'customer_id');
        $email = $this->stringArg($arguments, 'email');
        try {
            if ($id !== null) {
                $customer = $this->customerRepository->getById($id);
            } elseif ($email !== null) {
                $customer = $this->customerRepository->get($email, $this->intArg($arguments, 'website_id'));
            } else {
                throw new LocalizedException(__('Pass customer_id or email.'));
            }
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('No customer with %1.', $id !== null ? 'id ' . $id : 'that email'));
        }
        // Hyvä's customer scope guard is not registered for the MCP area: a role restricted to other websites may
        // not see this customer at all.
        $this->assertWebsiteAllowed((int) $customer->getWebsiteId(), false);
        return [
            'id' => (int) $customer->getId(),
            'email' => (string) $customer->getEmail(),
            'name' => trim($customer->getFirstname() . ' ' . $customer->getLastname()),
            'website_id' => (int) $customer->getWebsiteId(),
        ];
    }

    /**
     * Website settings by id.
     *
     * @param int $websiteId
     * @return array
     * @throws LocalizedException
     * @phpstan-return WebsiteStatus
     */
    protected function website(int $websiteId): array
    {
        foreach ($this->storeCredit->websites() as $website) {
            if ($website['website_id'] === $websiteId) {
                return $website;
            }
        }
        throw new LocalizedException(__('There is no website with id %1.', $websiteId));
    }

    /**
     * Refuse a website outside the MCP admin's scope restriction.
     *
     * @param int $websiteId
     * @param bool $forWrite
     * @return void
     * @throws AuthorizationException
     */
    protected function assertWebsiteAllowed(int $websiteId, bool $forWrite): void
    {
        $adminUserId = $this->adminUserId();
        $allowed = $forWrite
            ? $this->scopeGuard->allowsWebsiteForWrite($adminUserId, $websiteId)
            : $this->scopeGuard->allowsWebsite($adminUserId, $websiteId);
        if (!$allowed) {
            throw new AuthorizationException(__(
                'Your admin role is restricted to other websites; website %1 is not allowed.',
                $websiteId
            ));
        }
    }

    /**
     * Admin user id of the MCP caller (recorded on ledger entries).
     *
     * @return int|null
     */
    protected function adminUserId(): ?int
    {
        return $this->callerContext->adminUserId();
    }
}
