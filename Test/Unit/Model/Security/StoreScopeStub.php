<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security;

use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Real StoreScopeGuard over mocked Hyvä scope data: store views 1 and 2 exist (website 1 and 2, store group 1 and 2
 * with root category 11 and 12).
 */
trait StoreScopeStub
{
    /**
     * Guard for a caller restricted to the store views, or unrestricted (null).
     *
     * @param array|null $allowedStoreIds
     * @return StoreScopeGuard
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function storeScope(?array $allowedStoreIds): StoreScopeGuard
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $gateway = $this->createMock(AdminScopeGatewayInterface::class);
        $gateway->method('allowedScopes')->willReturn([
            'restricted' => $allowedStoreIds !== null,
            'website_ids' => $allowedStoreIds ?? [],
            'store_ids' => $allowedStoreIds ?? [],
        ]);
        $caller = $this->createMock(CallerContext::class);
        $caller->method('adminUserId')->willReturn(7);
        $stores = [];
        $groups = [];
        foreach ([1, 2] as $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getStoreGroupId')->willReturn($storeId);
            $stores[$storeId] = $store;
            $group = $this->createMock(GroupInterface::class);
            $group->method('getRootCategoryId')->willReturn(10 + $storeId);
            $groups[$storeId] = $group;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getGroup')->willReturnCallback(
            static fn (int|string|null $groupId): GroupInterface => $groups[(int) $groupId]
        );
        return new StoreScopeGuard(new AdminScopeGuard($modules, $gateway), $caller, $storeManager);
    }
}
