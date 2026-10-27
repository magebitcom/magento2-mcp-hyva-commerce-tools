<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Provider;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AiProviderGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

class ProviderStatus extends AbstractTool
{
    public const TOOL_NAME = 'hyva_ai.provider.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_ai_provider_status';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Ai::config';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param AiProviderGatewayInterface $aiProviders
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly AiProviderGatewayInterface $aiProviders
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Hyvä AI Provider Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Hyvä AI providers with whether each is configured (an API key is set; keys are never '
            . 'shown) and the model ids it offers. With Hyvä CMS AI Translations installed, also show whether '
            . 'translations are enabled, the chosen model, its provider and whether translation is ready to run.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::AI_PROVIDERS)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::AI_PROVIDERS)
            );
        }
        $providers = $this->aiProviders->providers();
        $translation = null;
        if ($this->featureChecker->isAvailable(FeatureCheckerInterface::CMS_AI_TRANSLATIONS)) {
            $settings = $this->aiProviders->translationSettings();
            $translation = $settings + ['ready' => $settings['enabled'] && $settings['provider_configured']];
        }
        return $this->jsonResult(
            ['providers' => $providers, 'cms_translation' => $translation],
            ['providers' => count($providers), 'translation_ready' => $translation['ready'] ?? null]
        );
    }
}
