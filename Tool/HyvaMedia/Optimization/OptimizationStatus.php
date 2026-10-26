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
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractOptimizationTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.optimization.status` MCP tool.
 */
class OptimizationStatus extends AbstractOptimizationTool
{
    public const TOOL_NAME = 'hyva_media.optimization.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_optimization_status';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $gateway
     * @param MediaFileInfo $fileInfo
     * @param ImageContentEditor $editor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaOptimizationGatewayInterface $gateway,
        private readonly MediaFileInfo $fileInfo,
        private readonly ImageContentEditor $editor
    ) {
        parent::__construct($featureChecker, $gateway);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Media Optimization Status for Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Optimization state of one image: generated resized and converted variants on disk, WebP/AVIF '
            . 'availability, retina setting, last generation time, configured engines and expected formats that are '
            . 'missing. Variants are generated on the first storefront request; nothing is generated here.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->required()
                ->description('Media path (e.g. wysiwyg/banner.jpg), /media/... path or media URL.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeOptimization(array $arguments): ToolResultInterface
    {
        $path = $this->editor->normalize($this->requireString($arguments, 'path'));
        $variants = $this->gateway->getVariants($path);
        $settings = $this->gateway->getConfigSummary();
        $expected = Cast::array($settings['output_formats'] ?? []);
        return $this->jsonResult([
            'path' => $path,
            'exists' => $this->fileInfo->exists($path),
            'optimization_enabled' => Cast::bool($settings['enabled'] ?? false),
            'retina' => Cast::bool($settings['retina'] ?? false),
            'resize_engine' => $settings['resize_engine'] ?? null,
            'conversion_engine' => $settings['conversion_engine'] ?? null,
            'webp' => in_array('webp', $variants['formats'], true),
            'avif' => in_array('avif', $variants['formats'], true),
            'missing_formats' => array_values(array_diff($expected, $variants['formats'])),
            'resized_count' => count($variants['resized']),
            'converted_count' => count($variants['converted']),
            'last_generated_at' => $variants['latest_generated_at'],
            'variants' => $variants,
        ], ['path' => $path, 'resized' => count($variants['resized']), 'converted' => count($variants['converted'])]);
    }
}
