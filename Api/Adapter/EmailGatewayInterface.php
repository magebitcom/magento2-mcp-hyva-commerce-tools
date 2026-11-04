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
 * Hyvä Email & Newsletter Templates access (Magento email/newsletter template rows + their Hyvä CMS content).
 * Only the adapter references Hyva\EmailTemplates classes; tools reach it through a proxy after the feature check.
 *
 * @phpstan-type EmailRow array{id: int, type: string, code: string, subject: string, orig_template_code: string,
 *     sender_name: string, sender_email: string, hyva_enabled: bool, has_draft: bool, has_published: bool,
 *     preheader: string, assigned_to: list<array{path: string, scope: string, scope_id: int}>}
 */
interface EmailGatewayInterface
{
    public const TYPE_EMAIL = 'email_template';
    public const TYPE_NEWSLETTER = 'newsletter_template';
    public const TYPES = [self::TYPE_EMAIL, self::TYPE_NEWSLETTER];

    /**
     * Templates of one type, newest first.
     *
     * @param string $type
     * @param string|null $search Code or subject contains.
     * @param int $page
     * @param int $pageSize
     * @return array
     * @phpstan-return array{total: int, items: list<EmailRow>}
     */
    public function getList(string $type, ?string $search, int $page, int $pageSize): array;

    /**
     * One template, or null.
     *
     * @param string $type
     * @param int $id Magento template id.
     * @return array|null
     * @phpstan-return EmailRow|null
     */
    public function get(string $type, int $id): ?array;

    /**
     * Core emails Hyvä ships designs for: orig_code, name, config_path and the template using that design, if any.
     *
     * @return array
     * @phpstan-return list<array{orig_code: string, name: string, config_path: string, template_id: int}>
     */
    public function coreDefinitions(): array;

    /**
     * Create (or reuse) the Hyvä version of a core email (Hyvä's own EmailTemplateManager::createAll()).
     *
     * @param string $origCode
     * @return array
     * @throws LocalizedException
     * @phpstan-return array{template_id: int, created: bool}
     */
    public function createFromCore(string $origCode): array;

    /**
     * Create a newsletter template with blank Hyvä content (same steps as Hyvä's admin "Create newsletter").
     *
     * @param array $settings code, subject, sender_name, sender_email, preheader
     * @return int
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $settings
     */
    public function createNewsletter(array $settings): int;

    /**
     * Update subject / preheader / sender (newsletter); returns the changed names.
     *
     * @param string $type
     * @param int $id
     * @param array $settings
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $settings
     * @phpstan-return list<string>
     */
    public function updateSettings(string $type, int $id, array $settings): array;

    /**
     * Render the draft or published Hyvä content with Hyvä's sample data, as the admin preview does.
     *
     * @param string $type
     * @param int $id
     * @param string $edition draft|published
     * @param int|null $storeId
     * @return string HTML
     * @throws LocalizedException
     */
    public function render(string $type, int $id, string $edition, ?int $storeId): string;

    /**
     * Send the published email template with sample data to one address (as Hyvä's admin "Send test").
     *
     * @param int $id
     * @param string $recipient
     * @param int|null $storeId
     * @return void
     * @throws LocalizedException
     */
    public function sendTest(int $id, string $recipient, ?int $storeId): void;

    /**
     * Email config paths and the template each scope sends, for every core email Hyvä has a design for.
     *
     * @return array
     * @phpstan-return list<array{orig_code: string, name: string, config_path: string,
     *     scopes: list<array{scope: string, scope_id: int, value: string, template_id: int}>}>
     */
    public function configUsage(): array;

    /**
     * What still uses a template, for a delete preview
     *
     * Email template: the store config values that send it (core_config_data rows of an email template field, any
     * scope, whose value is the template id), as "store_config". Newsletter template: the newsletters queued from it
     * that are not sent yet (not sent, sending or paused), as "queue"; sent and cancelled queues are history and only
     * counted ("queue_history"), but Magento deletes them with the template as well.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @phpstan-return array{used: bool, total: int,
     *     store_config?: list<array{path: string, scope: string, scope_id: int}>,
     *     queue?: list<array{queue_id: int, status: string, start_at: string|null}>, queue_history?: int}
     */
    public function deletionUsage(string $type, int $id): array;

    /**
     * Delete an email template through Magento's model, so Hyvä's rows cascade and its plugin prunes version history.
     *
     * @param int $id
     * @return void
     * @throws LocalizedException When it does not exist.
     */
    public function deleteTemplate(int $id): void;

    /**
     * Delete a newsletter template through Magento's model (its Hyvä rows, version history and queues go with it).
     *
     * @param int $id
     * @return void
     * @throws LocalizedException When it does not exist.
     */
    public function deleteNewsletter(int $id): void;

    /**
     * Whether Hyvä replaces sent emails with its published designs (hyva_email_templates/email_template/enabled)
     *
     * @return bool
     */
    public function isEmailReplacementEnabled(): bool;
}
