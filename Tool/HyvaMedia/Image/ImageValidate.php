<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Model\Media\ImageChecker;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.image.validate` MCP tool.
 */
class ImageValidate extends AbstractMediaTool
{
    public const TOOL_NAME = 'hyva_media.image.validate';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_validate';

    /**
     * @param ImageChecker $checker
     * @param ImageUsageIndex $usageIndex
     * @param MediaService $mediaService
     * @param ImageContentEditor $editor
     */
    public function __construct(
        private readonly ImageChecker $checker,
        private readonly ImageUsageIndex $usageIndex,
        private readonly MediaService $mediaService,
        private readonly ImageContentEditor $editor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Validate Media Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Validate one image: file exists, supported web format, file size and dimensions, alt text on every '
            . 'usage, hero/banner weight, missing optimized/WebP/AVIF variants, Image Editor support, transparent '
            . 'PNG and animated GIF.';
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
            ->integer('max_kb', fn (IntegerBuilder $i) => $i->minimum(1)->description('Size limit; default 500.'))
            ->integer('max_dimension', fn (IntegerBuilder $i) => $i->minimum(100)->description('Default 2560 px.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $path = $this->editor->normalize($this->requireString($arguments, 'path'));
        $result = $this->checker->check($path, $this->usageIndex->usagesOf($path), [
            'max_kb' => $this->intArg(
                $arguments,
                'max_kb',
                ImageChecker::DEFAULT_MAX_KB
            ) ?? ImageChecker::DEFAULT_MAX_KB,
            'max_dimension' => $this->intArg(
                $arguments,
                'max_dimension',
                ImageChecker::DEFAULT_MAX_DIMENSION
            ) ?? ImageChecker::DEFAULT_MAX_DIMENSION,
            'deep' => true,
            'variants' => $this->mediaService->getVariants($path),
            'output_formats' => $this->mediaService->getOutputFormats(),
        ]);
        $errors = count(array_filter($result['issues'], static fn (array $i): bool => $i['severity'] === 'error'));
        return $this->jsonResult(
            ['path' => $path, 'valid' => $errors === 0] + $result,
            ['path' => $path, 'issues' => count($result['issues'])]
        );
    }
}
