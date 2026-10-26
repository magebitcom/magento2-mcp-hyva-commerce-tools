<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.asset.resolve`.
 */
class AssetResolve extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.asset.resolve';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_asset_resolve';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaGalleryGatewayInterface $gallery
     * @param ImageReferenceExtractor $extractor
     * @param ImageUsageIndex $usageIndex
     * @param CallerContext $callerContext
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaGalleryGatewayInterface $gallery,
        ImageReferenceExtractor $extractor,
        ImageUsageIndex $usageIndex,
        CallerContext $callerContext,
        private readonly ContentScopeFilter $scopeFilter
    ) {
        parent::__construct($featureChecker, $gallery, $extractor, $usageIndex, $callerContext);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Resolve Media Gallery Asset';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'One Media Gallery asset by id or path: path, size, dimensions, keywords, whether the file exists, and '
            . 'where '
            . 'it is used (Hyvä CMS pages, blocks and menus by path or asset id, catalog, and Magento-tracked CMS '
            . 'content). Use it before moving or trashing an asset. Pages, blocks, menus, products and categories '
            . 'outside your role\'s websites/store views are left out and counted in usages_hidden_by_permissions.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)->description('Asset id (imageOptions.id).'))
            ->string('path', fn (StringBuilder $s) => $s->description('Media path, e.g. wysiwyg/home/home-main.jpg.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $id = $this->intArg($arguments, 'id');
        if ($id !== null) {
            $asset = $this->gallery->getByIds([$id])[0] ?? null;
        } else {
            $path = $this->extractor->normalizePath($this->requireString($arguments, 'path'));
            $asset = $path !== null ? $this->gallery->getByPath($path) : null;
        }
        if ($asset === null) {
            return $this->softError('No Media Gallery asset matches. Run hyva_media.sync.status if the file exists '
                . 'but is not in the gallery yet.');
        }
        $scoped = $this->scopeFilter->filterAssetUsages([$asset['id'] => $this->usage($asset)]);
        return $this->jsonResult($this->scopeFilter->withHidden([
            'asset' => $asset,
            'file_exists' => $this->gallery->fileExists($asset['path']),
            'keywords' => $this->gallery->keywords($asset['id']),
            'used_in' => $scoped['visible'][$asset['id']],
        ], 'usages_hidden_by_permissions', $scoped['hidden'][$asset['id']]), ['id' => $asset['id']]);
    }
}
