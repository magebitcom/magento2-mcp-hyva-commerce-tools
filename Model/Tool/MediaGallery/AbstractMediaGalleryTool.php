<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for the Hyvä Media Gallery tools (hyva_media.asset.*, .trash.*, .folder.*, .sync.*, .audit.literal_urls).
 *
 * Hyvä's gallery services check no permissions; tools are listed with Hyva_MediaGallery::* resources, the same
 * ones Hyvä's admin controllers use.
 */
abstract class AbstractMediaGalleryTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaGalleryGatewayInterface $gallery
     * @param ImageReferenceExtractor $extractor
     * @param ImageUsageIndex $usageIndex
     * @param CallerContext $callerContext
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly MediaGalleryGatewayInterface $gallery,
        protected readonly ImageReferenceExtractor $extractor,
        protected readonly ImageUsageIndex $usageIndex,
        protected readonly CallerContext $callerContext
    ) {
    }

    /**
     * Hyvä resource the tool is listed with.
     *
     * Media Gallery's resources only exist while the module is enabled; without it the tool stays listed under
     * Hyvä CMS access and answers that the feature is unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::MEDIA_GALLERY)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Hyva_Cms::cms';
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MEDIA_GALLERY)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::MEDIA_GALLERY)
            );
        }
        return $this->executeGallery($arguments);
    }

    /**
     * Tool body once Media Gallery is available.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeGallery(array $arguments): ToolResultInterface;

    /**
     * Where an asset is used.
     *
     * Hyvä CMS content (pages, blocks, menus, by path or asset id) and Magento-tracked content
     * (media_content_asset).
     *
     * @param array $asset
     * @return array
     * @phpstan-param array{id: int, path: string} $asset
     * @phpstan-return array{hyva_and_catalog: list<array<string, mixed>>, magento_tracked: list<array<string, mixed>>}
     */
    protected function usage(array $asset): array
    {
        return [
            'hyva_and_catalog' => array_values($this->usageIndex->usagesOf($asset['path'])),
            'magento_tracked' => $this->gallery->magentoUsage([$asset['id']])[$asset['id']] ?? [],
        ];
    }

    /**
     * Folder path under pub/media that the gallery may write to (wysiwyg/..., as Hyvä's folder controllers).
     *
     * @param string $path
     * @return string|null Error, or null when allowed.
     */
    protected function folderError(string $path): ?string
    {
        $normalized = trim($path, '/');
        if ($normalized === '' || str_contains($normalized, '..') || str_contains($normalized, '\\')) {
            return 'Give a folder path under wysiwyg/, without "..".';
        }
        if (!str_starts_with($normalized . '/', 'wysiwyg/')) {
            return 'Media Gallery folders live under wysiwyg/ (e.g. wysiwyg/campaigns/black-friday).';
        }
        if ($this->gallery->isExcluded($normalized)) {
            return sprintf('Magento excludes "%s" from the media gallery.', $normalized);
        }
        return null;
    }

    /**
     * Admin user id of the MCP caller (Trash records who trashed what).
     *
     * @return int|null
     */
    protected function adminUserId(): ?int
    {
        return $this->callerContext->adminUserId();
    }
}
