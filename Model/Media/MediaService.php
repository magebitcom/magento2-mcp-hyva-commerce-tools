<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaOptimizationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Media Optimization data when the feature is available, null otherwise (image tools keep working without it).
 */
class MediaService
{
    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $gateway
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly MediaOptimizationGatewayInterface $gateway
    ) {
    }

    /**
     * Is optimization available.
     *
     * @return bool
     */
    public function isOptimizationAvailable(): bool
    {
        return $this->featureChecker->isAvailable(FeatureCheckerInterface::MEDIA_OPTIMIZATION);
    }

    /**
     * Get variants.
     *
     * @param string $path
     * @return array|null
     * @phpstan-return array{resized: list<array{path: string, size: int, modified_at: string}>,
     *     converted: list<array{path: string, format: string, size: int, modified_at: string}>,
     *     formats: list<string>, latest_generated_at: string|null}|null
     */
    public function getVariants(string $path): ?array
    {
        return $this->isOptimizationAvailable() ? $this->gateway->getVariants($path) : null;
    }

    /**
     * Get settings.
     *
     * @return array|null
     * @phpstan-return array<string, mixed>|null
     */
    public function getSettings(): ?array
    {
        return $this->isOptimizationAvailable() ? $this->gateway->getConfigSummary() : null;
    }

    /**
     * Output formats the optimizer is configured to produce (empty when unavailable or disabled).
     *
     * @return array
     * @phpstan-return list<string>
     */
    public function getOutputFormats(): array
    {
        $settings = $this->getSettings();
        if ($settings === null || empty($settings['enabled'])) {
            return [];
        }
        $formats = [];
        foreach (Cast::array($settings['output_formats'] ?? []) as $format) {
            $formats[] = Cast::string($format);
        }
        return $formats;
    }
}
