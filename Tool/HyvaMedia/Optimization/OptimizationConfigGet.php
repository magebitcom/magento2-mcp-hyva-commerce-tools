<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Optimization;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaOptimizationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractOptimizationTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * `hyva_media.optimization.config_get` MCP tool.
 */
class OptimizationConfigGet extends AbstractOptimizationTool
{
    public const TOOL_NAME = 'hyva_media.optimization.config_get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_optimization_config_get';
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Cms::config_cms';
    public const SECTION = 'hyva_commerce_image_optimization';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $gateway
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaOptimizationGatewayInterface $gateway,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($featureChecker, $gateway);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Media Optimization Config';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read the Hyvä Media Optimization configuration (store scope optional): effective summary (engines, '
            . 'output formats, replacement modes, logging) and every raw setting group (optimization, post_hoc, '
            . 'html_css_replacement, viewport_breakpoints, gd_*/imagick_* quality settings).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeOptimization(array $arguments): ToolResultInterface
    {
        $storeId = $this->intArg($arguments, 'store_id');
        $raw = $storeId !== null
            ? $this->scopeConfig->getValue(self::SECTION, ScopeInterface::SCOPE_STORE, $storeId)
            : $this->scopeConfig->getValue(self::SECTION);
        return $this->jsonResult(
            [
                'store_id' => $storeId,
                'summary' => $this->gateway->getConfigSummary($storeId),
                'groups' => is_array($raw) ? $raw : [],
            ],
            ['store_id' => $storeId]
        );
    }
}
