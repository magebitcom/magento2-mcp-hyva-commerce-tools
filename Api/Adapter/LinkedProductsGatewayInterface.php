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
 * Hyvä Linked Products access. Only the adapter references Hyva\LinkedProducts classes; tools reach it through a
 * proxy, after the linked_products feature check.
 *
 * @phpstan-type GroupRow array{id: int, name: string, attributes: list<array{code: string, label: string}>,
 *     member_count: int, skus: list<string>, created_at: string, updated_at: string}
 * @phpstan-type MemberRow array{product_id: int, sku: string, on_storefront: bool, not_shown_because: string|null,
 *     values: array<string, string>}
 * @phpstan-type Issue array{severity: string, code: string, message: string}
 */
interface LinkedProductsGatewayInterface
{
    /**
     * Groups, newest first
     *
     * @param string|null $search Name contains
     * @param string|null $sku Only the group of this product
     * @param int $page
     * @param int $pageSize
     * @return array
     * @phpstan-return array{total: int, items: list<GroupRow>}
     */
    public function getList(?string $search, ?string $sku, int $page, int $pageSize): array;

    /**
     * One group
     *
     * @param int $groupId
     * @return array
     * @phpstan-return GroupRow
     * @throws LocalizedException When there is no such group.
     */
    public function get(int $groupId): array;

    /**
     * Id of the group with this name (case-insensitive), or null
     *
     * @param string $name
     * @return int|null
     */
    public function findIdByName(string $name): ?int;

    /**
     * Product ids by SKU; unknown SKUs are left out
     *
     * @param list<string> $skus
     * @return array<string, int>
     */
    public function productIdsBySkus(array $skus): array;

    /**
     * Product attributes that can link a group (user-defined dropdowns)
     *
     * @return list<array{code: string, label: string}>
     */
    public function linkableAttributes(): array;

    /**
     * What Hyvä would refuse (errors) and what would look wrong on the storefront (warnings) for a group
     *
     * @param int|null $groupId Group being edited, null for a new one
     * @param string $name
     * @param list<string> $attributeCodes
     * @param list<int> $productIds
     * @return array
     * @phpstan-return array{issues: list<Issue>, members: list<MemberRow>}
     */
    public function check(?int $groupId, string $name, array $attributeCodes, array $productIds): array;

    /**
     * Save a group through Hyvä's LinkedGroupSaver and refresh the cached product pages of old and new members
     *
     * @param int|null $groupId
     * @param string $name
     * @param list<string> $attributeCodes
     * @param list<int> $productIds
     * @return int Group id
     * @throws LocalizedException Hyvä refused the group; nothing was written.
     */
    public function save(?int $groupId, string $name, array $attributeCodes, array $productIds): int;
}
