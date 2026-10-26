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
        $paths = array_map('strval', array_values(
            array_unique(array_merge(array_keys($assets), $folder !== '' ? $this->inventory->scanFolder($folder) : []))
        ));
        $types = array_map('strtolower', $this->stringListArg($arguments, 'types'));
        if ($types !== []) {
            $paths = array_values(array_filter(
                $paths,
                static fn (string $path): bool => in_array(
                    strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                    $types,
                    true
                )
            ));
        }

        // The full usage index (every Hyvä document plus a catalog scan) only when a filter depends on usage.
        $usedIn = $this->stringArg($arguments, 'used_in');
        $filterUsed = array_key_exists('used', $arguments);
        $filterMissingAlt = $this->boolArg($arguments, 'missing_alt');
        $allUsages = null;
        if ($filterUsed || $usedIn !== null || $filterMissingAlt) {
            $allUsages = $this->usageIndex->all();
            $paths = array_values(array_filter(
                $paths,
                fn (string $path): bool => $this->matchesUsage(
                    $this->usagesFor($allUsages[$path] ?? [], $usedIn),
                    $filterUsed ? $this->boolArg($arguments, 'used') : null,
                    $filterMissingAlt
                )
            ));
        }

        // File info (stat + image size) for every candidate only when a size filter or the size sort needs it.
        $files = [];
        $sizeSort = ($this->stringArg($arguments, 'sort', 'size_desc') ?? 'size_desc') === 'size_desc';
        $minKb = $this->intArg($arguments, 'min_size_kb');
        $maxKb = $this->intArg($arguments, 'max_size_kb');
        $minWidth = $this->intArg($arguments, 'min_width');
        $minHeight = $this->intArg($arguments, 'min_height');
        if ($sizeSort || $minKb !== null || $maxKb !== null || $minWidth !== null || $minHeight !== null) {
            $kept = [];
            foreach ($paths as $path) {
                $file = $this->fileInfo->describe($path);
                $kb = (int) round(($file['size'] ?? 0) / 1024);
                if (($minKb !== null && $kb < $minKb) || ($maxKb !== null && $kb > $maxKb)
                    || ($file['width'] ?? 0) < ($minWidth ?? 0) || ($file['height'] ?? 0) < ($minHeight ?? 0)
                ) {
                    continue;
                }
                $files[$path] = $file;
                $kept[] = $path;
            }
            $paths = $kept;
        }
        if ($sizeSort) {
            usort($paths, static fn (string $a, string $b): int => (int) round(($files[$b]['size'] ?? 0) / 1024)
                <=> (int) round(($files[$a]['size'] ?? 0) / 1024));
        } else {
            sort($paths);
        }

        $total = count($paths);
        $page = array_slice($paths, 0, $this->intArg($arguments, 'limit', 100) ?? 100);
        $pageUsages = $allUsages ?? $this->usageIndex->usagesOfMany($page);
        $items = [];
        foreach ($page as $path) {
            $file = $files[$path] ?? $this->fileInfo->describe($path);
            $usages = $this->usagesFor($pageUsages[$path] ?? [], $usedIn);
            $items[] = [
                'path' => $path,
                'exists' => $file['exists'],
                'size_kb' => (int) round(($file['size'] ?? 0) / 1024),
                'width' => $file['width'],
                'height' => $file['height'],
                'type' => $file['extension'],
                'gallery_asset_id' => $assets[$path]['asset_id'] ?? null,
                'used' => $usages !== [],
                'usage_count' => count($usages),
                'used_in' => array_values(array_unique(array_column($usages, 'source'))),
                'usages_missing_alt' => $this->missingAlt($usages),
            ];
        }
        return $this->jsonResult(['total' => $total, 'items' => $items], ['total' => $total]);
    }

    /**
     * Usages limited to one source when used_in is given.
     *
     * @param array $usages
     * @param string|null $usedIn
     * @return array
     * @phpstan-param list<array{source: string, has_alt: bool}> $usages
     * @phpstan-return list<array{source: string, has_alt: bool}>
     */
    private function usagesFor(array $usages, ?string $usedIn): array
    {
        if ($usedIn === null) {
            return $usages;
        }
        return array_values(array_filter($usages, static fn (array $u): bool => $u['source'] === $usedIn));
    }

    /**
     * Whether an image's usages pass the used / missing_alt filters.
     *
     * @param array $usages
     * @param bool|null $used Null = no used filter.
     * @param bool $missingAlt
     * @return bool
     * @phpstan-param list<array{source: string, has_alt: bool}> $usages
     */
    private function matchesUsage(array $usages, ?bool $used, bool $missingAlt): bool
    {
        if ($used !== null && ($usages !== []) !== $used) {
            return false;
        }
        return !$missingAlt || $this->missingAlt($usages) > 0;
    }

    /**
     * Number of usages without alt text.
     *
     * @param array $usages
     * @return int
     * @phpstan-param list<array{source: string, has_alt: bool}> $usages
     */
    private function missingAlt(array $usages): int
    {
        return count(array_filter($usages, static fn (array $u): bool => !$u['has_alt']));
    }
}
