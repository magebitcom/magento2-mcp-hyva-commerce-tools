<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset;

use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.asset.search`.
 */
class AssetSearch extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.asset.search';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_asset_search';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Search Media Gallery Assets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search the Hyvä Media Gallery (media_gallery_asset): by folder, text, type, size and dimensions, or '
            . 'only '
            . 'likely duplicates (same file size and dimensions). Returns asset ids, paths, sizes and URLs; Hyvä CMS '
            . 'image fields reference assets by these ids.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('directory', fn (StringBuilder $s) => $s->description('Folder, e.g. wysiwyg/home. Default: all.'))
            ->string('search', fn (StringBuilder $s) => $s->description('Title or path contains.'))
            ->string('content_type', fn (StringBuilder $s) => $s->description('e.g. image.'))
            ->integer('min_width', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('min_height', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('min_size_kb', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('max_size_kb', fn (IntegerBuilder $i) => $i->minimum(0))
            ->boolean('duplicates_only', fn (BooleanBuilder $b) => $b->description('Only assets that share size '
                . 'and dimensions with another (Hyvä\'s duplicate heuristic).'))
            ->string('sort', fn (StringBuilder $s) => $s->enum(['updated_at', 'created_at', 'title', 'size']))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $directory = $this->stringArg($arguments, 'directory') ?? '';
        $filters = array_filter([
            'minWidth' => $this->intArg($arguments, 'min_width'),
            'minHeight' => $this->intArg($arguments, 'min_height'),
            'minSize' => ($this->intArg($arguments, 'min_size_kb') ?? 0) * 1024 ?: null,
            'maxSize' => ($this->intArg($arguments, 'max_size_kb') ?? 0) * 1024 ?: null,
            'duplicatesOnly' => $this->boolArg($arguments, 'duplicates_only') ?: null,
        ], static fn ($value): bool => $value !== null);
        $result = $this->gallery->search([
            'directory' => $directory,
            'search' => $this->stringArg($arguments, 'search') ?? '',
            'content_type' => $this->stringArg($arguments, 'content_type') ?? '',
            'page' => $this->intArg($arguments, 'page', 1),
            'page_size' => $this->intArg($arguments, 'page_size', 50),
            'sort' => $this->stringArg($arguments, 'sort', 'updated_at'),
            'all_directories' => $directory === '',
            'filters' => $filters,
        ]);
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
