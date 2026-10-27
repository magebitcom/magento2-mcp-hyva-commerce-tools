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
use Magebit\McpHyvaCommerceTools\Model\Media\ImageEngineInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractOptimizationTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;

/**
 * `hyva_media.optimization.config_validate` MCP tool.
 */
class OptimizationConfigValidate extends AbstractOptimizationTool
{
    public const TOOL_NAME = 'hyva_media.optimization.config_validate';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_optimization_config_validate';
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Cms::config_cms';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $gateway
     * @param ImageEngineInfo $engineInfo
     * @param ScopeConfigInterface $scopeConfig
     * @param AppState $appState
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaOptimizationGatewayInterface $gateway,
        private readonly ImageEngineInfo $engineInfo,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppState $appState
    ) {
        parent::__construct($featureChecker, $gateway);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Validate Media Optimization Config';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check the Media Optimization setup: optimization enabled, WebP/AVIF output, configured engines '
            . 'installed and able to write each format (AVIF needs engine support), automatic image replacement, '
            . 'debug/request logging in production mode, quality settings outside 50-95, viewport breakpoints, '
            . 'post-hoc processing needing queue consumers.';
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
        $settings = $this->gateway->getConfigSummary($storeId);
        $issues = [];
        $add = static function (string $severity, string $code, string $message) use (&$issues): void {
            $issues[] = ['severity' => $severity, 'code' => $code, 'message' => $message];
        };
        $formats = array_map(
            static fn ($f): string => Cast::string($f),
            Cast::array($settings['output_formats'] ?? [])
        );
        if (!Cast::bool($settings['enabled'] ?? false)) {
            $add('error', 'optimization_disabled', 'Image optimization is disabled (optimization/optimize).');
        }
        if (!in_array('webp', $formats, true)) {
            $add('warning', 'webp_disabled', 'WebP output is not enabled.');
        }
        if (!in_array('avif', $formats, true)) {
            $add('info', 'avif_disabled', 'AVIF output is not enabled.');
        }
        foreach (['resize_engine', 'conversion_engine'] as $key) {
            $engine = Cast::string($settings[$key] ?? '');
            $info = $this->engineInfo->getEngines()[$engine] ?? null;
            if ($info === null || !$info['available']) {
                $add(
                    'error',
                    $key . '_unavailable',
                    sprintf('Configured %s "%s" is not available in PHP.', str_replace('_', ' ', $key), $engine)
                );
                continue;
            }
            if ($key === 'conversion_engine') {
                foreach ($formats as $format) {
                    if (!$this->engineInfo->supports($engine, $format)) {
                        $add(
                            'error',
                            $format . '_unsupported',
                            sprintf(
                                '%s output is enabled but %s cannot write %s.',
                                strtoupper($format),
                                $engine,
                                strtoupper($format)
                            )
                        );
                    }
                }
            }
        }
        if (!Cast::bool($settings['automatic_replacement'] ?? false)) {
            $add('warning', 'automatic_replacement_disabled', 'Automatic <img> replacement is off.');
        }
        $production = $this->appState->getMode() === AppState::MODE_PRODUCTION;
        if ($production && Cast::bool($settings['debug_log'] ?? false)) {
            $add('warning', 'debug_log_in_production', 'Debug logging is enabled in production mode.');
        }
        if ($production && Cast::bool($settings['image_request_log'] ?? false)) {
            $add('warning', 'request_log_in_production', 'Image request logging is enabled in production mode.');
        }
        foreach (['gd_resize', 'gd_conversion', 'imagick_resize', 'imagick_conversion'] as $group) {
            $values = $this->scopeConfig->getValue('hyva_commerce_image_optimization/' . $group);
            foreach (is_array($values) ? $values : [] as $field => $value) {
                if (str_ends_with(
                    (string) $field,
                    '_quality'
                ) && is_numeric($value) && ((int) $value < 50 || (int) $value > 95)
                ) {
                    $add(
                        'warning',
                        'quality_out_of_range',
                        sprintf('%s/%s = %d (recommended 50-95).', $group, (string) $field, (int) $value)
                    );
                }
            }
        }
        $breakpoints = $this->scopeConfig->getValue(
            'hyva_commerce_image_optimization/viewport_breakpoints/viewport_breakpoints'
        );
        if (!$this->scopeConfig->isSetFlag(
            'hyva_commerce_image_optimization/viewport_breakpoints/enable_viewport_breakpoints'
        ) || $breakpoints === null || $breakpoints === '' || $breakpoints === '[]'
        ) {
            $add(
                'warning',
                'resize_config_missing',
                'Viewport breakpoints are disabled or empty; images are not resized per viewport.'
            );
        }
        if (Cast::bool($settings['post_hoc_resize'] ?? false) || Cast::bool($settings['post_hoc_convert'] ?? false)) {
            $add(
                'info',
                'post_hoc_enabled',
                'Post-hoc processing needs the hyva.media.optimization.* queue consumers running.'
            );
        }
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $counts[$issue['severity']]++;
        }
        return $this->jsonResult(
            [
                'valid' => $counts['error'] === 0,
                'counts' => $counts,
                'issues' => $issues,
                'engines' => $this->engineInfo->getEngines(),
                'settings' => $settings,
            ],
            $counts
        );
    }
}
