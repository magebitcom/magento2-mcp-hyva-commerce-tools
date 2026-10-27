<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Media;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaOptimizationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;

/**
 * Base for hyva_media.optimization.* tools: soft "feature not available" result without Hyvä Media Optimization.
 */
abstract class AbstractOptimizationTool extends AbstractMediaTool
{
    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $gateway
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly MediaOptimizationGatewayInterface $gateway
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MEDIA_OPTIMIZATION)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::MEDIA_OPTIMIZATION)
            );
        }
        return $this->executeOptimization($arguments);
    }

    /**
     * Execute optimization.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeOptimization(array $arguments): ToolResultInterface;
}
