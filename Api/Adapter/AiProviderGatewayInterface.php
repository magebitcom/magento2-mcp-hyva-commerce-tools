<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Api\Adapter;

/**
 * Hyvä AI provider readiness (read only). Only the adapter references Hyva\Ai classes; tools reach it through a
 * proxy, after the ai_providers feature check. API keys are never read: only the providers' own "configured" flag.
 */
interface AiProviderGatewayInterface
{
    /**
     * Registered providers with their readiness and model ids
     *
     * @return array
     * @phpstan-return list<array{name: string, label: string, configured: bool, models: list<string>}>
     */
    public function providers(): array;

    /**
     * Hyvä CMS AI Translations settings and the provider behind the chosen model
     *
     * Provider is null when the model is empty or no provider offers it.
     *
     * @return array
     * @phpstan-return array{enabled: bool, model: string, provider: string|null, provider_configured: bool}
     */
    public function translationSettings(): array;
}
