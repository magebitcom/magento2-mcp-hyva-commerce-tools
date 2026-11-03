<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Provider as ContentProvider;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Throwable;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Signed storefront preview URLs (Hyvä GetPreviewQuery, valid ~7 days, tied to the current draft uid)
 * and the admin Liveview editor URL.
 *
 * Store rows are limited to the store views a website / store view restricted caller may see.
 */
class PreviewService
{
    /**
     * @param ContentProvider $contentProvider
     * @param BackendUrl $backendUrl
     * @param LoggerInterface $logger
     * @param StoreScopeGuard $storeScopeGuard
     * @param StoreManagerInterface $storeManager
     * @param ContentIndex $contentIndex
     */
    public function __construct(
        private readonly ContentProvider $contentProvider,
        private readonly BackendUrl $backendUrl,
        private readonly LoggerInterface $logger,
        private readonly StoreScopeGuard $storeScopeGuard,
        private readonly StoreManagerInterface $storeManager,
        private readonly ContentIndex $contentIndex
    ) {
    }

    /**
     * Get preview.
     *
     * @param string $type
     * @param int $id
     * @param int|null $storeId Limit to one store view.
     * @param int|null $versionId Preview a version-history item instead of the draft.
     * @return array
     * @phpstan-return array{admin_editor_url: string, stores: list<array<string, mixed>>, error: string|null,
     *     note: string, stores_hidden_by_permissions?: int}
     */
    public function getPreview(string $type, int $id, ?int $storeId = null, ?int $versionId = null): array
    {
        $stores = [];
        $error = null;
        try {
            $data = $this->contentProvider->getStoreContentData(
                $type,
                $id,
                $versionId !== null ? (string) $versionId : ''
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Hyvä preview data could not be built.',
                ['type' => $type, 'id' => $id, 'exception' => $e]
            );
            $data = [];
            $error = $e->getMessage();
        }
        $visible = $this->storeScopeGuard->restrictedStoreIds();
        $menuStoreIds = $this->menuStoreIds($type, $id);
        $hidden = 0;
        foreach ($data as $store) {
            if (!is_array($store)) {
                continue;
            }
            $store = $this->withMenuAssignment($store, $menuStoreIds);
            if ($storeId !== null && Cast::int($store['store_id'] ?? -1) !== $storeId) {
                continue;
            }
            if ($visible !== null && !in_array(Cast::int($store['store_id'] ?? -1), $visible, true)) {
                $hidden++;
                continue;
            }
            $stores[] = [
                'store_id' => Cast::int($store['store_id'] ?? 0),
                'code' => $this->storeCode($store),
                'is_assigned' => (bool) ($store['is_assigned'] ?? false),
                'preview_url' => $store['preview_url'] ?? null,
                'published_url' => $this->liveUrl($store),
            ];
        }

        $preview = [
            'admin_editor_url' => $this->backendUrl->getUrl(
                'liveview/editor/index',
                ['type' => $type, 'id' => $id, '_nosecret' => true]
            ),
            'stores' => $stores,
            'error' => $error,
            'note' => 'preview_url shows the current draft (or the given version) and needs no admin login. It is '
                . 'an HMAC capability link: valid up to 7 days and only while the draft is unchanged; links issued '
                . 'before Hyvä CMS 1.3 no longer work. published_url is the live address as Hyvä CMS reports it '
                . '(null when the entity has no live URL in that store: inactive, or not assigned to it).',
        ];
        if ($hidden > 0) {
            $preview['stores_hidden_by_permissions'] = $hidden;
        }
        return $preview;
    }

    /**
     * Live URL per store view, as Hyvä CMS reports it (store row published_url).
     *
     * None for an inactive entity or a store view it is not assigned to.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @phpstan-return list<array{store_id: int, code: string|null, published_url: string}>
     */
    public function getPublishedUrls(string $type, int $id): array
    {
        try {
            $data = $this->contentProvider->getStoreContentData($type, $id);
        } catch (Throwable $e) {
            $this->logger->error(
                'Hyvä store rows could not be read.',
                ['type' => $type, 'id' => $id, 'exception' => $e]
            );
            return [];
        }
        $visible = $this->storeScopeGuard->restrictedStoreIds();
        $menuStoreIds = $this->menuStoreIds($type, $id);
        $urls = [];
        foreach ($data as $store) {
            if (!is_array($store)
                || ($visible !== null && !in_array(Cast::int($store['store_id'] ?? -1), $visible, true))
            ) {
                continue;
            }
            $url = $this->liveUrl($this->withMenuAssignment($store, $menuStoreIds));
            if ($url !== null) {
                $urls[] = [
                    'store_id' => Cast::int($store['store_id'] ?? 0),
                    'code' => $this->storeCode($store),
                    'published_url' => $url,
                ];
            }
        }
        return $urls;
    }

    /**
     * Store view code of a Hyvä store row; Hyvä's menu provider leaves it out of its rows (T-87).
     *
     * @param array $store
     * @return string|null
     * @phpstan-param array<mixed> $store
     */
    private function storeCode(array $store): ?string
    {
        if (is_string($store['code'] ?? null) && $store['code'] !== '') {
            return $store['code'];
        }
        if (!isset($store['store_id'])) {
            return null;
        }
        try {
            return (string) $this->storeManager->getStore(Cast::int($store['store_id']))->getCode();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * Store views a menu is assigned to, or null to keep Hyvä's rows as they are.
     *
     * Null when this is not a menu, the menu is missing from the index, the lookup fails or the menu has store
     * view 0 (all store views). A menu with no store view rows (its only store view was deleted) gives an empty
     * list, so no store view is assigned, as Hyvä's frontend loader (inner join on the store table) shows it nowhere.
     *
     * @param string $type
     * @param int $id
     * @return list<int>|null
     */
    private function menuStoreIds(string $type, int $id): ?array
    {
        if ($type !== ContentLocator::TYPE_MENU || $id <= 0) {
            return null;
        }
        try {
            $storeIds = $this->contentIndex->getStoreIds($type, [$id]);
        } catch (Throwable $e) {
            $this->logger->error('Hyvä menu store views could not be read.', ['id' => $id, 'exception' => $e]);
            return null;
        }
        if (!array_key_exists($id, $storeIds)) {
            return null;
        }
        return in_array(0, $storeIds[$id], true) ? null : $storeIds[$id];
    }

    /**
     * Store row with is_assigned set from the menu's own store views.
     *
     * Hyvä's MenuProvider::getStoreContentData() hard-codes is_assigned => true for every store view, so a menu
     * assigned only to store view 1 would still report a live URL for store view 2.
     *
     * @param array $store
     * @param list<int>|null $menuStoreIds
     * @return array
     * @phpstan-param array<mixed> $store
     * @phpstan-return array<mixed>
     */
    private function withMenuAssignment(array $store, ?array $menuStoreIds): array
    {
        if ($menuStoreIds !== null && !in_array(Cast::int($store['store_id'] ?? -1), $menuStoreIds, true)) {
            $store['is_assigned'] = false;
        }
        return $store;
    }

    /**
     * Live URL of a Hyvä store row, or null when the entity is inactive or not assigned to that store view.
     *
     * Hyvä's page provider reports the page's address for every store view, active or not (T-86); the block and
     * form providers already leave unassigned store views out. Hyvä's menu provider marks every store view assigned,
     * so menu rows get their is_assigned flag from the menu's own store views first (withMenuAssignment()).
     *
     * @param array $store
     * @return string|null
     * @phpstan-param array<mixed> $store
     */
    private function liveUrl(array $store): ?string
    {
        $url = $store['published_url'] ?? null;
        if (!is_string($url) || $url === '') {
            return null;
        }
        foreach (['is_active', 'is_assigned'] as $flag) {
            if (array_key_exists($flag, $store) && !Cast::bool($store[$flag])) {
                return null;
            }
        }
        return $url;
    }
}
