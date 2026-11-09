<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api;

/**
 * Reports whether a Hyvä Commerce feature is installed, enabled and allowed for the caller.
 *
 * @internal Implementations may gain methods in minor releases.
 */
interface FeatureCheckerInterface
{
    public const CMS = 'cms';
    public const MENU_BUILDER = 'menu_builder';
    public const MEDIA_OPTIMIZATION = 'media_optimization';
    public const IMAGE_EDITOR = 'image_editor';
    public const ADMIN_DASHBOARD = 'admin_dashboard';
    public const MEDIA_GALLERY = 'media_gallery';
    public const FORM_BUILDER = 'form_builder';
    public const EMAIL_TEMPLATES = 'email_templates';
    public const STORE_CREDIT = 'store_credit';
    public const LINKED_PRODUCTS = 'linked_products';
    public const CATEGORY_MERCHANDISER = 'category_merchandiser';
    public const AI_WORKER = 'ai_worker';
    public const ADMIN_PERMISSIONS = 'admin_permissions';
    public const CMS_AI_TRANSLATIONS = 'cms_ai_translations';
    public const CMS_TAILWIND = 'cms_tailwind';
    public const AI_PROVIDERS = 'ai_providers';

    /**
     * Installed, enabled and allowed for the current admin (when one is known).
     *
     * @param string $feature
     * @return bool
     */
    public function isAvailable(string $feature): bool;

    /**
     * Installed and enabled, whatever the caller's ACL: whether the feature's data exists at all.
     *
     * @param string $feature
     * @return bool
     */
    public function isInstalledAndEnabled(string $feature): bool;

    /**
     * Get status.
     *
     * The acl_allowed key is null when the feature has no ACL resource, is not installed or disabled, or no admin
     * context exists; otherwise whether the caller's role holds acl_resource. The unavailable_reason key says why
     * available is false (not_installed, disabled or acl, in that order) and is null when available is true.
     *
     * @param string $feature
     * @return array
     * @phpstan-return array{ feature: string, label: string, available: bool,
     *     unavailable_reason: 'not_installed'|'disabled'|'acl'|null, installed: bool, enabled: bool,
     *     version: string|null, package: string, acl_resource: string|null, acl_allowed: bool|null,
     *     modules: array<string, array{installed: bool, enabled: bool}> }
     */
    public function getStatus(string $feature): array;

    /**
     * Status of every known feature, keyed by feature code.
     *
     * @return array
     * @phpstan-return array<string, array<string, mixed>>
     */
    public function getAll(): array;

    /**
     * Body of the soft "feature not available" tool response.
     *
     * @param string $feature
     * @return array
     * @phpstan-return array{available: false, feature: string, reason: string, hint: string}
     */
    public function getUnavailableResponse(string $feature): array;
}
