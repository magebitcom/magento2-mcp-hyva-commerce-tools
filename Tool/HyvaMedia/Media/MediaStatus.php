<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Media;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaOptimizationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageEngineInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * `hyva_media.status`: Media Optimization / Image Editor availability, engines and key settings.
 */
class MediaStatus extends AbstractTool
{
    public const TOOL_NAME = 'hyva_media.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_status';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $mediaGateway
     * @param ImageEngineInfo $imageEngineInfo
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly MediaOptimizationGatewayInterface $mediaGateway,
        private readonly ImageEngineInfo $imageEngineInfo,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Media Optimization Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check whether Hyvä Media Optimization and Image Editor are installed and enabled, the new Media '
            . 'Gallery state, GD/Imagick availability with supported formats, WebP/AVIF output, automatic image '
            . 'replacement, debug logging and ACL status. Call before other hyva_media.* tools.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Store scope for settings. Defaults to the default scope.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $optimization = $this->featureChecker->getStatus(FeatureCheckerInterface::MEDIA_OPTIMIZATION);
        $imageEditor = $this->featureChecker->getStatus(FeatureCheckerInterface::IMAGE_EDITOR);

        $settings = null;
        if ($optimization['installed'] && $optimization['enabled']) {
            $settings = $this->mediaGateway->getConfigSummary($this->intArg($arguments, 'store_id'));
        }
        $outputFormats = is_array($settings['output_formats'] ?? null) ? $settings['output_formats'] : [];

        $payload = [
            'media_optimization' => $optimization,
            'image_editor' => $imageEditor,
            'new_media_gallery_enabled' => $this->scopeConfig->isSetFlag('system/media_gallery/enabled'),
            'engines' => $this->imageEngineInfo->getEngines(),
            'optimization_enabled' => (bool) ($settings['enabled'] ?? false),
            'webp_enabled' => in_array('webp', $outputFormats, true),
            'avif_enabled' => in_array('avif', $outputFormats, true),
            'automatic_replacement_enabled' => (bool) ($settings['automatic_replacement'] ?? false),
            'debug_log_enabled' => (bool) ($settings['debug_log'] ?? false),
            'cli_clear_command' => $optimization['available'] ? 'bin/magento hyva:media-optimization:clear [path] '
                . '[--converted-only|--resized-only]' : null,
            'settings' => $settings,
        ];

        return $this->jsonResult($payload, [
            'media_optimization' => $optimization['available'],
            'image_editor' => $imageEditor['available'],
        ]);
    }
}
