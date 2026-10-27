<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Sync;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaSyncReport;
use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.sync.status`.
 */
class SyncStatus extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.sync.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_sync_status';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Media Gallery Sync Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Is the Media Gallery index in step with the files? Compares the gallery folders on disk with '
            . 'media_gallery_asset (files not indexed, assets whose file is gone), shows the synchronization queue and '
            . 'whether Magento\'s enhanced gallery is on (with the old gallery on, new uploads are not indexed). Fix: '
            . 'bin/magento media-gallery:sync.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('sample', fn (IntegerBuilder $i) => $i->minimum(0)->maximum(100)
                ->description('Example paths per difference (default 10).'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $snapshot = $this->gallery->syncSnapshot();
        $report = $this->syncReport->compare(
            $snapshot['files'],
            $snapshot['asset_paths'],
            $this->intArg($arguments, 'sample', 10) ?? 10
        );
        $pending = ($snapshot['queue']['new'] ?? 0) + ($snapshot['queue']['in_progress'] ?? 0)
            + ($snapshot['queue']['retry'] ?? 0);
        return $this->jsonResult($report + [
            'folders' => $snapshot['folders'],
            'enhanced_gallery_enabled' => $snapshot['gallery_enabled'],
            'queue' => $snapshot['queue'],
            'queue_pending' => $pending,
            'trash_items' => $snapshot['trash'],
            'next_step' => $report['in_sync'] && $pending === 0 ? null : 'Run bin/magento media-gallery:sync (and '
                . 'the media.gallery.synchronization consumer when messages are pending).',
            'warning' => $snapshot['gallery_enabled'] ? null : 'Stores > Configuration > Advanced > System > Media '
                . 'Gallery: "Enable Old Media Gallery" is Yes, so Magento does not index new uploads.',
        ], ['in_sync' => $report['in_sync'], 'missing_in_db' => $report['missing_in_db']]);
    }

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaGalleryGatewayInterface $gallery
     * @param ImageReferenceExtractor $extractor
     * @param ImageUsageIndex $usageIndex
     * @param CallerContext $callerContext
     * @param MediaSyncReport $syncReport
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaGalleryGatewayInterface $gallery,
        ImageReferenceExtractor $extractor,
        ImageUsageIndex $usageIndex,
        CallerContext $callerContext,
        private readonly MediaSyncReport $syncReport
    ) {
        parent::__construct($featureChecker, $gallery, $extractor, $usageIndex, $callerContext);
    }
}
