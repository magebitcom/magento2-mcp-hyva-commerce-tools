<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpHyvaCommerceTools\Api\ImageFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;

/**
 * `hyva_media.image.get`: image details composed by field resolvers.
 */
class ImageGet extends AbstractMediaTool
{
    public const TOOL_NAME = 'hyva_media.image.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_get';

    /**
     * @param ImageContentEditor $editor
     * @param ResolverPipeline $pipeline
     * @param ImageFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly ImageContentEditor $editor,
        private readonly ResolverPipeline $pipeline,
        private readonly array $fieldResolvers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Media Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $keys = [];
        foreach ($this->fieldResolvers as $resolver) {
            $keys[] = $resolver->getKey();
        }
        return 'Details of one image: file size, dimensions, type and dates, Media Gallery title, every usage with '
            . 'alt-text state, optimized variants with WebP/AVIF status, and Image Editor backup. Response keys: '
            . implode(', ', $keys) . '.';
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
                ->description('Media path, /media/... path or media URL.'))
            ->array('fields', fn (ArrayBuilder $a) => $a->ofStrings())
            ->array('exclude', fn (ArrayBuilder $a) => $a->ofStrings())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $path = $this->editor->normalize($this->requireString($arguments, 'path'));
        $response = ['path' => $path];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            if ($resolver instanceof ImageFieldResolverInterface) {
                $response[$resolver->getKey()] = $resolver->resolve($path, $arguments);
            }
        }
        return $this->jsonResult($response, ['path' => $path]);
    }
}
