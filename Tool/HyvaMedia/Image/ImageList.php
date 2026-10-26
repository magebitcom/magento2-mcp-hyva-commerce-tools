<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaInventory;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.image.list` MCP tool.
 */
class ImageList extends AbstractMediaTool
{
    public const TOOL_NAME = 'hyva_media.image.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_list';

    /**
     * @param MediaInventory $inventory
     * @param MediaFileInfo $fileInfo
     * @param ImageUsageIndex $usageIndex
     */
    public function __construct(
        private readonly MediaInventory $inventory,
        private readonly MediaFileInfo $fileInfo,
        private readonly ImageUsageIndex $usageIndex
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Media Images';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List images from the Media Gallery and a media folder (default wysiwyg) with size, dimensions, type, '
            . 'whether and where they are used (Hyvä CMS, menus, Magento CMS, product/category descriptions) and '
            . 'missing alt text. Filter by folder, type, size (KB), dimensions, used/unused, usage source.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('folder', fn (StringBuilder $s) => $s
                ->description('Folder under pub/media; default wysiwyg. Use "" for the gallery only.'))
            ->array('types', fn (ArrayBuilder $a) => $a->ofStrings()->description('Extensions, e.g. ["png","jpg"].'))
            ->integer('min_size_kb', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('max_size_kb', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('min_width', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('min_height', fn (IntegerBuilder $i) => $i->minimum(0))
            ->boolean('used', fn (BooleanBuilder $b) => $b->description('true = referenced somewhere, false = unused.'))
            ->string('used_in', fn (StringBuilder $s) => $s->enum(ImageUsageIndex::SOURCES))
            ->boolean('missing_alt', fn (BooleanBuilder $b) => $b
                ->description('Only images with at least one usage without alt text.'))
            ->string('sort', fn (StringBuilder $s) => $s
                ->enum(['path', 'size_desc'])
                ->description('Default size_desc.'))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(1000)->description('Default 100.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $folder = array_key_exists('folder', $arguments) ? Cast::string($arguments['folder']) : 'wysiwyg';
        $assets = $this->inventory->getGalleryAssets();
        $paths = array_values(
            array_unique(array_merge(array_keys($assets), $folder !== '' ? $this->inventory->scanFolder($folder) : []))
        );
        $types = array_map('strtolower', $this->stringListArg($arguments, 'types'));
        $minKb = $this->intArg($arguments, 'min_size_kb');
        $maxKb = $this->intArg($arguments, 'max_size_kb');
        $usedIn = $this->stringArg($arguments, 'used_in');

        $allUsages = $this->usageIndex->all();
        $items = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            $file = $this->fileInfo->describe($path);
            if ($types !== [] && !in_array($file['extension'], $types, true)) {
                continue;
            }
            $kb = (int) round(($file['size'] ?? 0) / 1024);
            if (($minKb !== null && $kb < $minKb) || ($maxKb !== null && $kb > $maxKb)) {
                continue;
            }
            if (($file['width'] ?? 0) < ($this->intArg($arguments, 'min_width') ?? 0) || ($file['height'] ?? 0) < (
                    $this->intArg($arguments, 'min_height') ?? 0
                )
            ) {
                continue;
            }
            $usages = $allUsages[$path] ?? [];
            if ($usedIn !== null) {
                $usages = array_values(array_filter($usages, static fn (array $u): bool => $u['source'] === $usedIn));
            }
            if (array_key_exists('used', $arguments) && ($usages !== []) !== $this->boolArg($arguments, 'used')) {
                continue;
            }
            $missingAlt = count(array_filter($usages, static fn (array $u): bool => !$u['has_alt']));
            if ($this->boolArg($arguments, 'missing_alt') && $missingAlt === 0) {
                continue;
            }
            $items[] = [
                'path' => $path,
                'exists' => $file['exists'],
                'size_kb' => $kb,
                'width' => $file['width'],
                'height' => $file['height'],
                'type' => $file['extension'],
                'gallery_asset_id' => $assets[$path]['asset_id'] ?? null,
                'used' => $usages !== [],
                'usage_count' => count($usages),
                'used_in' => array_values(array_unique(array_column($usages, 'source'))),
                'usages_missing_alt' => $missingAlt,
            ];
        }
        if (($this->stringArg($arguments, 'sort', 'size_desc') ?? 'size_desc') === 'size_desc') {
            usort($items, static fn (array $a, array $b): int => $b['size_kb'] <=> $a['size_kb']);
        }
        $total = count($items);
        return $this->jsonResult(
            ['total' => $total, 'items' => array_slice($items, 0, $this->intArg($arguments, 'limit', 100) ?? 100)],
            ['total' => $total]
        );
    }
}
