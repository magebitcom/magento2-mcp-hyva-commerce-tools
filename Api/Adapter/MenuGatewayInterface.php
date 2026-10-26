<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

use Magento\Framework\Exception\LocalizedException;

/**
 * Module-owned boundary to Hyva_MenuBuilder. Only the implementation may reference Hyva\MenuBuilder classes.
 *
 * Menu rows are returned as:
 * array{id: int, title: string, identifier: string, is_active: bool, store_ids: list<int>,
 *       preview_url_key: string|null, has_draft: bool, is_published: bool,
 *       draft_differs: bool, creation_time: string|null, update_time: string|null}
 */
interface MenuGatewayInterface
{
    /**
     * Get list.
     *
     * @param array $filters
     * @return array
     * @phpstan-param array{store_id?: int|null, is_active?: bool|null, search?: string|null} $filters
     * @phpstan-return list<array<string, mixed>>
     */
    public function getList(array $filters = []): array;

    /**
     * Get.
     *
     * @param int $menuId
     * @return array
     * @throws LocalizedException When the menu does not exist.
     * @phpstan-return array<string, mixed>
     */
    public function get(int $menuId): array;

    /**
     * Find id by identifier.
     *
     * @param string $identifier
     * @param int|null $storeId Null searches every store, active or not.
     * @return int|null
     */
    public function findIdByIdentifier(string $identifier, ?int $storeId = null): ?int;

    /**
     * Create a menu row (title, identifier, store_ids, is_active, preview_url_key).
     *
     * Content is saved separately as a draft.
     *
     * @param array $data
     * @return int
     * @throws LocalizedException
     * @phpstan-param array{title: string, identifier: string, store_ids: list<int>, is_active?: bool,
     *     preview_url_key?: string|null} $data
     */
    public function create(array $data): int;

    /**
     * Update menu settings (title, identifier, store_ids, is_active, preview_url_key). Applies immediately.
     *
     * @param int $menuId
     * @param array $data
     * @return array Changed fields.
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $data
     * @phpstan-return list<string>
     */
    public function update(int $menuId, array $data): array;

    /**
     * Render the published menu on the storefront of a store view, the way Hyvä does.
     *
     * @param string $identifier
     * @param int $storeId
     * @return array
     * @phpstan-return array{html: string, message: string|null, error: string|null}
     */
    public function render(string $identifier, int $storeId): array;
}
