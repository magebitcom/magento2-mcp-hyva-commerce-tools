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
use Magebit\Mcp\Api\LoggerInterface;
use Throwable;

/**
 * Signed storefront preview URLs (Hyvä GetPreviewQuery, valid ~7 days, tied to the current draft uid)
 * and the admin Liveview editor URL.
 */
class PreviewService
{
    /**
     * @param ContentProvider $contentProvider
     * @param BackendUrl $backendUrl
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ContentProvider $contentProvider,
        private readonly BackendUrl $backendUrl,
        private readonly LoggerInterface $logger
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
     *     note: string}
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
        foreach ($data as $store) {
            if (!is_array($store)) {
                continue;
            }
            if ($storeId !== null && (int) ($store['store_id'] ?? -1) !== $storeId) {
                continue;
            }
            $stores[] = [
                'store_id' => (int) ($store['store_id'] ?? 0),
                'code' => $store['code'] ?? null,
                'is_assigned' => (bool) ($store['is_assigned'] ?? false),
                'preview_url' => $store['preview_url'] ?? null,
                'published_url' => $store['published_url'] ?? null,
            ];
        }

        return [
            'admin_editor_url' => $this->backendUrl->getUrl(
                'liveview/editor/index',
                ['type' => $type, 'id' => $id, '_nosecret' => true]
            ),
            'stores' => $stores,
            'error' => $error,
            'note' => 'preview_url shows the current draft (or the given version) and needs no admin login. It is '
                . 'an HMAC capability link: valid up to 7 days and only while the draft is unchanged; links issued '
                . 'before Hyvä CMS 1.3 no longer work. published_url is the live address as Hyvä CMS reports it '
                . '(null when the entity has no live URL in that store).',
        ];
    }

    /**
     * Live URL per store view, as Hyvä CMS reports it (store row published_url).
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
        $urls = [];
        foreach ($data as $store) {
            if (is_array($store) && is_string($store['published_url'] ?? null) && $store['published_url'] !== '') {
                $urls[] = [
                    'store_id' => (int) ($store['store_id'] ?? 0),
                    'code' => is_string($store['code'] ?? null) ? $store['code'] : null,
                    'published_url' => $store['published_url'],
                ];
            }
        }
        return $urls;
    }
}
