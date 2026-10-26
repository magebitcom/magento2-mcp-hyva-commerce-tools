<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.image.find_usage` MCP tool.
 */
class ImageFindUsage extends AbstractMediaTool
{
    public const TOOL_NAME = 'hyva_media.image.find_usage';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_find_usage';

    /**
     * @param ImageUsageIndex $usageIndex
     * @param MediaFileInfo $fileInfo
     * @param ImageContentEditor $editor
     */
    public function __construct(
        private readonly ImageUsageIndex $usageIndex,
        private readonly MediaFileInfo $fileInfo,
        private readonly ImageContentEditor $editor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Find Media Image Usage';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find everywhere an image is used: Hyvä CMS pages and blocks (draft and published, with component uid '
            . 'and field), Menu Builder rich content, Magento CMS / Page Builder content, product and category '
            . 'descriptions. Use before replacing or deleting an image.';
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
    protected function run(array $arguments): ToolResultInterface
    {
        $path = $this->editor->normalize($this->requireString($arguments, 'path'));
        $usages = $this->usageIndex->usagesOf($path);
        $bySource = [];
        foreach ($usages as $usage) {
            $bySource[$usage['source']] = ($bySource[$usage['source']] ?? 0) + 1;
        }
        return $this->jsonResult([
            'path' => $path,
            'exists' => $this->fileInfo->exists($path),
            'total' => count($usages),
            'by_source' => $bySource,
            'live_usages' => count(array_filter($usages, static fn (array $u): bool => $u['edition'] !== 'draft')),
            'usages' => $usages,
        ], ['total' => count($usages)]);
    }
}
