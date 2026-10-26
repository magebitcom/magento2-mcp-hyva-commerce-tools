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
 * Hyvä Form Builder access. Only the adapter references Hyva\FormBuilder classes; tools reach it through a proxy,
 * after the form_builder feature check.
 *
 * @phpstan-type FormRow array{id: int, identifier: string, title: string, is_active: bool, store_ids: list<int>,
 *     recipient_to: string, recipient_cc: string, recipient_bcc: string, subject_template: string,
 *     webhook_url: string, webhook_format: string, is_captcha_enabled: bool, preview_url_key: string,
 *     has_draft: bool, has_published: bool, updated_at: string}
 */
interface FormGatewayInterface
{
    /**
     * Settings a tool may write on the form entity (content goes through the Hyvä CMS provider).
     */
    public const SETTINGS = [
        'title',
        'identifier',
        'store_ids',
        'is_active',
        'recipient_to',
        'recipient_cc',
        'recipient_bcc',
        'subject_template',
        'webhook_url',
        'webhook_format',
        'is_captcha_enabled',
        'preview_url_key',
    ];
    public const WEBHOOK_FORMATS = ['form_encoded', 'json_flat', 'json'];

    /**
     * Forms, newest first.
     *
     * @param string|null $search Title or identifier contains.
     * @param int|null $storeId Assigned to this store view (or all store views).
     * @param int $page
     * @param int $pageSize
     * @return array
     * @phpstan-return array{total: int, items: list<FormRow>}
     */
    public function getList(?string $search, ?int $storeId, int $page, int $pageSize): array;

    /**
     * One form, active or not.
     *
     * @param int $id
     * @return array|null
     * @phpstan-return FormRow|null
     */
    public function get(int $id): ?array;

    /**
     * Forms with this identifier (active or not), optionally assigned to a store view.
     *
     * @param string $identifier
     * @param int|null $storeId
     * @return array
     * @phpstan-return list<FormRow>
     */
    public function findByIdentifier(string $identifier, ?int $storeId = null): array;

    /**
     * Create the form entity (no content yet).
     *
     * @param array $settings
     * @return int
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $settings
     */
    public function create(array $settings): int;

    /**
     * Update entity settings; returns the changed setting names.
     *
     * @param int $id
     * @param array $settings
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $settings
     * @phpstan-return list<string>
     */
    public function update(int $id, array $settings): array;

    /**
     * Why the identifier cannot be used on these store views (Hyvä's own rules), or null.
     *
     * @param string $identifier
     * @param array $storeIds
     * @param int|null $excludeId
     * @return string|null
     * @phpstan-param list<int> $storeIds
     */
    public function identifierError(string $identifier, array $storeIds, ?int $excludeId = null): ?string;

    /**
     * A free identifier based on $base for these store views.
     *
     * @param string $base
     * @param array $storeIds
     * @return string
     * @phpstan-param list<int> $storeIds
     */
    public function suggestIdentifier(string $base, array $storeIds): string;
}
