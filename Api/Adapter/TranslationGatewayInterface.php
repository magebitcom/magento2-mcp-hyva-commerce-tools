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
 * Hyvä CMS AI Translations access. Only the adapter references Hyva\CmsAiTranslations classes; tools reach it
 * through a proxy, after the cms_ai_translations feature check.
 */
interface TranslationGatewayInterface
{
    /**
     * Translation settings and the store views that get translations
     *
     * @return array
     * @phpstan-return array{enabled: bool, model: string, default_language: string,
     *     stores: list<array{store_id: int, code: string, store_locale: string, target_locale: string|null}>}
     */
    public function settings(): array;

    /**
     * Translate texts with the configured AI model, the way the editor's Translate panel does (synchronous)
     *
     * @param array $items
     * @return array<string, string> translationKey => translated text
     * @throws LocalizedException Not enabled, no model, no API key, or the provider failed.
     * @phpstan-param list<array{fieldId: string, originalText: string,
     *     targetStores: list<array{storeId: int, storeCode: string, translationKey: string}>}> $items
     */
    public function translate(array $items): array;
}
