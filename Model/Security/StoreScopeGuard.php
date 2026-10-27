<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Security;

use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store view scope of the MCP caller for entities assigned to store views (CMS pages/blocks, menus, forms), with
 * the rules Hyvä Advanced Admin Permissions applies to store-linked entities in the admin:
 * - read: an entity is visible when it has no store assignment or one of its store views is allowed; store view 0
 *   ("All Store Views") is always visible. Lists show only entities linked to an allowed store view or to 0.
 * - write: every assigned store view must be allowed; store view 0 is inherited by every store view, so it needs
 *   all of them. Re-assigning may not drop a store view outside the role either.
 * - data without a store assignment (global): a restricted role may not change it.
 * - catalog (read): a product is visible in an allowed website or in none, a category under the root category of
 *   an allowed store view (Hyvä's CatalogProductEntityGuard and CatalogCategoryEntityGuard).
 * Unrestricted callers (no MCP admin, scope module disabled, role without a restriction) pass every check.
 */
class StoreScopeGuard
{
    /**
     * @var array<string, list<int>> Category root ids per allowed store view list.
     */
    private array $categoryRootIds = [];

    /**
     * @param AdminScopeGuard $adminScopeGuard
     * @param CallerContext $callerContext
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly AdminScopeGuard $adminScopeGuard,
        private readonly CallerContext $callerContext,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Store views the caller is restricted to (website restrictions expanded to their store views), or null
     *
     * @return array|null Null when the caller is not restricted.
     * @phpstan-return list<int>|null
     */
    public function restrictedStoreIds(): ?array
    {
        $scopes = $this->adminScopeGuard->allowedScopes($this->callerContext->adminUserId());
        return $scopes['restricted'] ? $scopes['store_ids'] : null;
    }

    /**
     * Store view ids a list may show entities of (the allowed ones plus 0), or null when unrestricted
     *
     * @return array|null
     * @phpstan-return non-empty-list<int>|null
     */
    public function visibleStoreIds(): ?array
    {
        $allowed = $this->restrictedStoreIds();
        return $allowed === null ? null : array_values(array_unique([0, ...$allowed]));
    }

    /**
     * Whether the caller may see an entity with these store views
     *
     * @param array $storeIds
     * @return bool
     * @phpstan-param list<int> $storeIds
     */
    public function allowsRead(array $storeIds): bool
    {
        $allowed = $this->restrictedStoreIds();
        if ($allowed === null || $storeIds === []) {
            return true;
        }
        foreach ($storeIds as $storeId) {
            if ($storeId === 0 || in_array($storeId, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the caller may see a product in these websites
     *
     * Hyvä's CatalogProductEntityGuard: visible when one of its websites is allowed; a product in no website is
     * in no storefront and stays visible.
     *
     * @param array $websiteIds
     * @return bool
     * @phpstan-param list<int> $websiteIds
     */
    public function allowsProductWebsites(array $websiteIds): bool
    {
        $scopes = $this->adminScopeGuard->allowedScopes($this->callerContext->adminUserId());
        if (!$scopes['restricted'] || $websiteIds === []) {
            return true;
        }
        foreach ($websiteIds as $websiteId) {
            if ($websiteId === 0 || in_array($websiteId, $scopes['website_ids'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the caller may see a category with this tree path (e.g. "1/2/15")
     *
     * Hyvä's CatalogCategoryEntityGuard: visible when it sits under the root category of an allowed store view's
     * store group; the tree root ("1") carries no scope.
     *
     * @param string $path
     * @return bool
     */
    public function allowsCategoryPath(string $path): bool
    {
        $allowed = $this->restrictedStoreIds();
        if ($allowed === null) {
            return true;
        }
        $segments = array_map('intval', explode('/', $path));
        if (count($segments) < 2) {
            return true;
        }
        return in_array($segments[1], $this->categoryRootIds($allowed), true);
    }

    /**
     * Whether the caller may change an entity with these store views
     *
     * @param array $storeIds
     * @return bool
     * @phpstan-param list<int> $storeIds
     */
    public function allowsWrite(array $storeIds): bool
    {
        $allowed = $this->restrictedStoreIds();
        if ($allowed === null || $storeIds === []) {
            return true;
        }
        if (in_array(0, $storeIds, true)) {
            $storeIds = $this->allStoreIds();
        }
        return array_diff($storeIds, $allowed) === [];
    }

    /**
     * Whether the caller may move an entity from one store view assignment to another
     *
     * @param array $currentStoreIds Empty for a new entity.
     * @param array $newStoreIds
     * @return bool
     * @phpstan-param list<int> $currentStoreIds
     * @phpstan-param list<int> $newStoreIds
     */
    public function allowsAssignment(array $currentStoreIds, array $newStoreIds): bool
    {
        $allowed = $this->restrictedStoreIds();
        if ($allowed === null) {
            return true;
        }
        if (!$this->allowsWrite($newStoreIds)) {
            return false;
        }
        foreach (array_diff($currentStoreIds, $newStoreIds) as $removedStoreId) {
            if ($removedStoreId !== 0 && !in_array($removedStoreId, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Refuse an entity the caller may not see
     *
     * @param string $label E.g. "CMS page #5".
     * @param array $storeIds
     * @return void
     * @throws AuthorizationException
     * @phpstan-param list<int> $storeIds
     */
    public function assertReadable(string $label, array $storeIds): void
    {
        if (!$this->allowsRead($storeIds)) {
            throw new AuthorizationException(__(
                'Your admin role is restricted to other websites or store views; %1 (store views %2) is outside it.',
                $label,
                implode(', ', $storeIds)
            ));
        }
    }

    /**
     * Store view a store-bound render or send runs in: restricted callers never fall back to the default store view
     *
     * A restricted caller without a store view gets the first one its role may read; store view 0 ("All Store
     * Views") and views outside the role are refused. Unrestricted callers keep the given value (null: default).
     *
     * @param int|null $storeId
     * @return int|null
     * @throws AuthorizationException
     */
    public function resolveStoreId(?int $storeId): ?int
    {
        $allowed = $this->restrictedStoreIds();
        if ($allowed === null) {
            return $storeId;
        }
        if ($storeId === null) {
            if ($allowed === []) {
                throw new AuthorizationException(__('Your admin role is restricted and has no readable store view.'));
            }
            return $allowed[0];
        }
        if ($storeId === 0) {
            throw new AuthorizationException(__(
                'Your admin role is restricted to some websites or store views; store view 0 ("All Store Views") '
                . 'is outside it. Pass the store_id of one of your store views.'
            ));
        }
        $this->assertReadable('store view ' . $storeId, [$storeId]);
        return $storeId;
    }

    /**
     * Refuse a change to an entity the caller may not change
     *
     * @param string $label
     * @param array $storeIds
     * @return void
     * @throws AuthorizationException
     * @phpstan-param list<int> $storeIds
     */
    public function assertWritable(string $label, array $storeIds): void
    {
        $this->assertReadable($label, $storeIds);
        if (!$this->allowsWrite($storeIds)) {
            throw new AuthorizationException(__(
                'Your admin role is restricted to some websites or store views and may not change %1: it is '
                . 'assigned to store views %2. Only content assigned to your own store views can be changed '
                . '(store view 0, "All Store Views", needs every store view).',
                $label,
                implode(', ', $storeIds)
            ));
        }
    }

    /**
     * Refuse a store view assignment the caller may not make
     *
     * @param string $label
     * @param array $currentStoreIds Empty for a new entity.
     * @param array $newStoreIds
     * @return void
     * @throws AuthorizationException
     * @phpstan-param list<int> $currentStoreIds
     * @phpstan-param list<int> $newStoreIds
     */
    public function assertAssignable(string $label, array $currentStoreIds, array $newStoreIds): void
    {
        if (!$this->allowsAssignment($currentStoreIds, $newStoreIds)) {
            throw new AuthorizationException(__(
                'Your admin role is restricted to some websites or store views and may not assign %1 to store '
                . 'views %2 (store view 0, "All Store Views", needs every store view; removing a store view '
                . 'outside your role is not allowed either).',
                $label,
                implode(', ', $newStoreIds)
            ));
        }
    }

    /**
     * Refuse a change to data shared by every store view (no store assignment) for a restricted caller
     *
     * @param string $what E.g. "Email templates".
     * @return void
     * @throws AuthorizationException
     */
    public function assertUnrestricted(string $what): void
    {
        if ($this->restrictedStoreIds() !== null) {
            throw new AuthorizationException(__(
                '%1 apply to every store view; a role restricted to some websites or store views may not '
                . 'change them.',
                $what
            ));
        }
    }

    /**
     * Root category ids of the store groups of these store views (Hyvä's AllowedCategoryRoots)
     *
     * @param array $storeIds
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return list<int>
     */
    private function categoryRootIds(array $storeIds): array
    {
        $key = implode(',', $storeIds);
        if (!isset($this->categoryRootIds[$key])) {
            $rootIds = [];
            foreach ($this->storeManager->getStores() as $store) {
                if (!in_array((int) $store->getId(), $storeIds, true)) {
                    continue;
                }
                $group = $this->storeManager->getGroup((string) $store->getStoreGroupId());
                $rootId = (int) $group->getRootCategoryId();
                if ($rootId > 0) {
                    $rootIds[$rootId] = $rootId;
                }
            }
            $this->categoryRootIds[$key] = array_values($rootIds);
        }
        return $this->categoryRootIds[$key];
    }

    /**
     * Every store view id, the admin store (0) excluded
     *
     * @return array
     * @phpstan-return list<int>
     */
    private function allStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $ids[] = (int) $store->getId();
        }
        return $ids;
    }
}
