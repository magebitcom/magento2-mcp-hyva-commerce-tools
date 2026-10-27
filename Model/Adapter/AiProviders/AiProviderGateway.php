<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\AiProviders;

use Hyva\Ai\Api\ProviderPoolInterface;
use Hyva\Ai\Api\ProviderResolverInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AiProviderGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Adapter over Hyvä AI (provider pool and resolver).
 *
 * Never reads `hyva_ai/<provider>/api_key` (encrypted): readiness is the providers' own isConfigured().
 */
class AiProviderGateway implements AiProviderGatewayInterface
{
    private const CONFIG_TRANSLATION_ENABLED = 'hyva_ai/hyva_cms_translations/enabled';
    private const CONFIG_TRANSLATION_MODEL = 'hyva_ai/hyva_cms_translations/model';

    /**
     * @param OptionalServiceFactory $optionalServices
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function providers(): array
    {
        $configurations = $this->optionalServices->get(ProviderResolverInterface::class)
            ->getProviderConfigurations();
        $rows = [];
        foreach ($this->optionalServices->get(ProviderPoolInterface::class)->getAll() as $name => $provider) {
            $name = (string) $name;
            $configuration = is_array($configurations[$name] ?? null) ? $configurations[$name] : [];
            $models = [];
            foreach (is_array($configuration['options'] ?? null) ? $configuration['options'] : [] as $option) {
                if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                    $models[] = (string) $option['value'];
                }
            }
            $label = Cast::string($configuration['display_label'] ?? $configuration['label'] ?? '');
            $rows[] = [
                'name' => $name,
                'label' => $label !== '' ? $label : $name,
                'configured' => $provider->isConfigured(),
                'models' => $models,
            ];
        }
        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function translationSettings(): array
    {
        $model = Cast::string($this->scopeConfig->getValue(self::CONFIG_TRANSLATION_MODEL));
        $provider = $model === '' ? null : $this->resolveProvider($model);
        $pool = $this->optionalServices->get(ProviderPoolInterface::class);
        return [
            'enabled' => Cast::bool($this->scopeConfig->getValue(self::CONFIG_TRANSLATION_ENABLED)),
            'model' => $model,
            'provider' => $provider,
            'provider_configured' => $provider !== null && $pool->has($provider)
                && $pool->get($provider)->isConfigured(),
        ];
    }

    /**
     * Provider offering the model, or null when none does.
     *
     * @param string $model
     * @return string|null
     */
    private function resolveProvider(string $model): ?string
    {
        try {
            return $this->optionalServices->get(ProviderResolverInterface::class)->resolveProviderFromModel($model);
        } catch (LocalizedException) {
            return null;
        }
    }
}
