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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.optimization.preview_picture` MCP tool.
 */
class OptimizationPreviewPicture extends AbstractOptimizationTool
{
    public const TOOL_NAME = 'hyva_media.optimization.preview_picture';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_optimization_preview_picture';

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
        return 'Preview Responsive Picture';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Return the responsive <picture> HTML Hyvä Media Optimization produces for an image (srcset per format '
            . 'and breakpoint), with the parsed sources. Give one width/height, or "images" entries {path, width, '
            . 'height, media} for art direction. Read only: the markup uses the variants that already exist, and '
            . 'missing lists the ones Hyvä will create when the image is next rendered on the storefront. Image type '
            . 'breakpoints are not expanded.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Media path; required unless "images" is given.'))
            ->integer('width', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('height', fn (IntegerBuilder $i) => $i->minimum(1))
            ->rawProperty(
                'images',
                [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Advanced: [{path, width?, height?, media?, sizes?, fallback?}], smallest first.',
                ]
            )
            ->string('alt', fn (StringBuilder $s) => $s)
            ->string('loading', fn (StringBuilder $s) => $s->enum(['lazy', 'eager']))
            ->string('fetchpriority', fn (StringBuilder $s) => $s->enum(['high', 'low', 'auto']))
            ->string('sizes', fn (StringBuilder $s) => $s)
            ->string('class', fn (StringBuilder $s) => $s)
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeOptimization(array $arguments): ToolResultInterface
    {
        $images = [];
        foreach ($this->arrayArg($arguments, 'images') as $entry) {
            if (is_array($entry) && isset($entry['path'])) {
                $entry['path'] = $this->editor->normalize(Cast::string($entry['path']));
                $images[] = $entry;
            }
        }
        if ($images === []) {
            $path = $this->editor->normalize($this->requireString($arguments, 'path'));
            $file = $this->fileInfo->describe($path);
            if (!$file['exists']) {
                return $this->softError(sprintf('"%s" does not exist.', $path));
            }
            $width = $this->intArg($arguments, 'width');
            $height = $this->intArg($arguments, 'height');
            // As in Hyvä: one dimension keeps the aspect ratio; none serves the original file as 1x.
            $images[] = ['path' => $path, 'width' => $width, 'height' => $height];
        }
        $imgAttributes = [];
        foreach (['alt', 'loading', 'fetchpriority', 'sizes', 'class'] as $attribute) {
            $value = $this->stringArg($arguments, $attribute);
            if ($value !== null) {
                $imgAttributes[$attribute] = $value;
            }
        }
        $preview = $this->gateway->previewPicture(array_values($images), $imgAttributes);
        return $this->jsonResult(
            [
                'html' => $preview['html'],
                'sources' => $preview['sources'],
                'missing' => $preview['missing'],
                'images' => $images,
                'note' => $preview['missing'] === [] ? null : 'Nothing was generated. The missing variants are '
                    . 'created when the image is next rendered on the storefront.',
            ],
            ['sources' => count($preview['sources']), 'missing' => count($preview['missing'])]
        );
    }
}
