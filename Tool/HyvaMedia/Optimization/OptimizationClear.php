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
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractOptimizationTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.optimization.clear` MCP tool.
 */
class OptimizationClear extends AbstractOptimizationTool
{
    public const TOOL_NAME = 'hyva_media.optimization.clear';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_optimization_clear';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_save';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaOptimizationGatewayInterface $gateway
     * @param ImageContentEditor $editor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaOptimizationGatewayInterface $gateway,
        private readonly ImageContentEditor $editor
    ) {
        parent::__construct($featureChecker, $gateway);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Clear Optimized Images';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete generated (resized/converted) variants of one image path so they regenerate on the next '
            . 'request; mode all, resized or converted. all_images=true clears every generated image in the store '
            . 'and needs "confirm": true (without it, returns how many files would be removed). Original images are '
            . 'never touched.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Media path; required unless all_images=true.'))
            ->string('mode', fn (StringBuilder $s) => $s
                ->enum(['all', 'resized', 'converted'])
                ->description('Default all.'))
            ->boolean('all_images', fn (BooleanBuilder $b) => $b
                ->description('Clear generated variants of every image.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Required with all_images=true.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeOptimization(array $arguments): ToolResultInterface
    {
        $mode = $this->stringArg($arguments, 'mode', 'all') ?? 'all';
        if ($this->boolArg($arguments, 'all_images')) {
            if (!$this->boolArg($arguments, 'confirm')) {
                return $this->confirmationRequired(
                    'clear_all_optimized_images',
                    [$this->gateway->countAllGenerated() + ['mode' => $mode]]
                );
            }
            $removed = $this->gateway->clearAll($mode);
            return $this->jsonResult(
                ['cleared' => 'all_images', 'mode' => $mode, 'files_removed' => $removed],
                ['scope' => 'all', 'removed' => $removed]
            );
        }
        $path = $this->editor->normalize($this->requireString($arguments, 'path'));
        $removed = $this->gateway->clear($path, $mode);
        return $this->jsonResult([
            'cleared' => $path,
            'mode' => $mode,
            'files_removed' => $removed,
            'remaining' => $this->gateway->getVariants($path),
            'note' => 'Variants are regenerated on the next storefront request for this image.',
        ], ['path' => $path, 'removed' => $removed]);
    }
}
