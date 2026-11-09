<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Security;

use Magebit\McpHyvaCommerceTools\Exception\OutOfScopeException;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Store view scope of the MCP caller for entities assigned to store views (CMS pages/blocks, menus, forms), with
 * the rules Hyvä Advanced Admin Permissions applies to store-linked entities in the admin:
 * - read: an entity is visible when it has no store assignment or one of its store views is allowed; store view 0
 *   ("All Store Views") is always visible. Lists show only entities linked to an allowed store view or to 0.
 * - write: every assigned store view must be allowed; store view 0 is inherited by every store view, so it needs
 *   all of them (Hyvä DataFieldWriteRule::isStoreWriteDenied() with GlobalWritePolicy). This covers create too.
 * - re-assign: judged by the new assignment, which must be writable, and may not drop a store view outside the
 *   role; dropping store view 0 is not dropping a foreign store view (Hyvä EntityScopeWriteGuard
 *   ::unassignsForeignScope()). So a restricted role may move an "All Store Views" entity into its own store
 *   views, as the Hyvä admin lets it, while it may not change it where it is.
 * - data without a store assignment (global): a restricted role may not change it.
 * - catalog (read): a product is visible in an allowed website or in none, a category under the root category of
 *   an allowed store view (Hyvä's CatalogProductEntityGuard and CatalogCategoryEntityGuard).
 * Unrestricted callers (no MCP admin, scope module disabled, role without a restriction) pass every check.
 */
class StoreScopeGuard
{
    /**
     * Opening of every store scope refusal in this module, so all of them read the same.
     */
    public const RESTRICTED = 'Your admin role is restricted to some websites or store views';

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
     * Admin user id of the caller when a website / store view restriction applies to it, or null when unrestricted
     *
     * @return int|null
     */
    public function restrictedAdminUserId(): ?int
    {
        $adminUserId = $this->callerContext->adminUserId();
        if ($adminUserId === null) {
            return null;
        }
        return $this->adminScopeGuard->allowedScopes($adminUserId)['restricted'] ? $adminUserId : null;
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
            throw new OutOfScopeException(__(
                '%1; %2 (%3) is outside it.',
                self::RESTRICTED,
                $label,
                $this->describeStoreViews($storeIds)
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
                throw new OutOfScopeException(__('Your admin role is restricted and has no readable store view.'));
            }
            return $allowed[0];
        }
        if ($storeId === 0) {
            throw new OutOfScopeException(__(
                '%1; store view 0 ("All Store Views") is outside it. Pass the store_id of one of your store views.',
                self::RESTRICTED
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
            // describeStoreViews([0]) already names "All Store Views": say the rule once, after it.
            throw new OutOfScopeException(__(
                $storeIds === [0]
                    ? '%1 and may not change %2: it is assigned to %3, which needs every store view. Only content '
                        . 'assigned to your own store views can be changed.'
                    : '%1 and may not change %2: it is assigned to %3. Only content assigned to your own store views '
                        . 'can be changed (store view 0, "All Store Views", needs every store view).',
                self::RESTRICTED,
                $label,
                $this->describeStoreViews($storeIds)
            ));
        }
    }

    /**
     * Refuse a change to an existing entity, judged as the Hyvä admin judges the save
     *
     * Without a new assignment the entity's store views must be writable (assertWritable()). A save that
     * re-assigns it is judged by the assignment it enters instead: the entity must be readable and the move
     * allowed (assertAssignable()), so an "All Store Views" entity can be moved into the caller's store views.
     * An empty new assignment enters no store view: it would only drop the current ones (the entity goes offline
     * everywhere), so it is judged as a change where the entity is.
     *
     * @param string $label
     * @param array $storeIds Current assignment.
     * @param array|null $newStoreIds Assignment this call saves, or null when it keeps the current one.
     * @return void
     * @throws AuthorizationException
     * @phpstan-param list<int> $storeIds
     * @phpstan-param list<int>|null $newStoreIds
     */
    public function assertChangeable(string $label, array $storeIds, ?array $newStoreIds): void
    {
        if ($newStoreIds === null || $newStoreIds === []) {
            $this->assertWritable($label, $storeIds);
            return;
        }
        $this->assertReadable($label, $storeIds);
        $this->assertAssignable($label, $storeIds, $newStoreIds);
    }

    /**
     * Whether only the re-assignment allows the change: the caller may not change the entity where it is
     *
     * Such a call (assertChangeable() with a new assignment) must move the entity before anything else is written
     * to it, e.g. a draft: written first, a draft would stay on an entity the caller may not change when the move
     * then fails.
     *
     * @param array $storeIds Current assignment.
     * @param array|null $newStoreIds Assignment this call saves, or null when it keeps the current one.
     * @return bool
     * @phpstan-param list<int> $storeIds
     * @phpstan-param list<int>|null $newStoreIds
     */
    public function changesOnlyAfterReassignment(array $storeIds, ?array $newStoreIds): bool
    {
        return $newStoreIds !== null && $newStoreIds !== [] && !$this->allowsWrite($storeIds);
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
            $denied = $this->deniedStoreIds($newStoreIds);
            throw new OutOfScopeException(__(
                '%1 and may not assign %2 to %3: %4.',
                self::RESTRICTED,
                $label,
                $this->describeStoreViews($newStoreIds),
                $this->assignmentRefusalReason($currentStoreIds, $newStoreIds)
            ), $denied !== [] ? ['denied_store_ids' => $denied] : []);
        }
    }

    /**
     * Requested store views the caller may not assign an entity to (each one judged alone by allowsWrite())
     *
     * Store view 0 ("All Store Views") is listed unless the role holds every store view. A move refused only for
     * the store views it removes has no denied requested store view: the result is empty then.
     *
     * @param array $storeIds
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return list<int>
     */
    public function deniedStoreIds(array $storeIds): array
    {
        if ($this->restrictedStoreIds() === null) {
            return [];
        }
        return array_values(array_filter(
            array_values(array_unique($storeIds)),
            fn (int $id): bool => !$this->allowsWrite([$id])
        ));
    }

    /**
     * Why allowsAssignment() refused a move, naming only the rule it broke
     *
     * The new assignment is checked first (store view 0 needs every store view; others must be the role's), then
     * the store views the move would remove (store view 0 is never counted as removed).
     *
     * @param array $currentStoreIds
     * @param array $newStoreIds
     * @return string
     * @phpstan-param list<int> $currentStoreIds
     * @phpstan-param list<int> $newStoreIds
     */
    private function assignmentRefusalReason(array $currentStoreIds, array $newStoreIds): string
    {
        $allowed = $this->restrictedStoreIds() ?? [];
        if (in_array(0, $newStoreIds, true) && !$this->allowsWrite([0])) {
            return $newStoreIds === [0]
                ? 'it needs every store view'
                : 'store view 0 ("All Store Views") needs every store view';
        }
        $foreign = array_values(array_diff($newStoreIds, $allowed, [0]));
        if ($foreign !== []) {
            return $this->describeStoreViews($foreign) . (count($foreign) === 1 ? ' is' : ' are')
                . ' outside your role';
        }
        $removed = array_values(array_diff($currentStoreIds, $newStoreIds, $allowed, [0]));
        return 'that removes it from ' . $this->describeStoreViews($removed) . ', '
            . (count($removed) === 1 ? 'which is' : 'which are') . ' outside your role';
    }

    /**
     * Refuse a change to data shared by every store view (no store assignment) for a restricted caller
     *
     * @param string $what Plural, e.g. "email templates".
     * @return void
     * @throws AuthorizationException
     */
    public function assertUnrestricted(string $what): void
    {
        if ($this->restrictedStoreIds() !== null) {
            throw new OutOfScopeException(__(
                '%1 and may not change %2: they apply to every store view.',
                self::RESTRICTED,
                $what
            ));
        }
    }

    /**
     * "store view 1" / "store views 1, 2" for a refusal message; [0] is 'store view 0 ("All Store Views")'
     *
     * Shared by every scope refusal so the store view wording stays the same; callers add their own suffix.
     *
     * @param array $storeIds
     * @return string
     * @phpstan-param list<int> $storeIds
     */
    public function describeStoreViews(array $storeIds): string
    {
        if ($storeIds === [0]) {
            return 'store view 0 ("All Store Views")';
        }
        return (count($storeIds) === 1 ? 'store view ' : 'store views ') . implode(', ', $storeIds);
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
