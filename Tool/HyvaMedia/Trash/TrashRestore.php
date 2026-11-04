<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Trash;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Media\SharedMedia;

/**
 * `hyva_media.trash.restore`.
 */
class TrashRestore extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.trash.restore';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_trash_restore';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_delete';
    public const IS_WRITE = true;

    /**
     * Hyvä keeps no asset id in the trash, so a restored file is indexed again under a new one (T-159).
     */
    public const NEW_ID_NOTE = 'A restored asset gets a new asset id (asset_id): Hyvä keeps no id in the trash. '
        . 'References to the old id are stale: stale_references lists the image fields that still hold it (their '
        . 'old id is asset_id, collected in previous_asset_ids). They still render (Hyvä falls back to the stored '
        . 'path) but lose the link to the asset; re-link them with hyva_media_image_replace using the same path as '
        . 'old_path and new_path (saves drafts with the new id; publish them).';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaGalleryGatewayInterface $gallery
     * @param ImageReferenceExtractor $extractor
     * @param ImageUsageIndex $usageIndex
     * @param CallerContext $callerContext
     * @param LiteralUrlAuditor $literalUrlAuditor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaGalleryGatewayInterface $gallery,
        ImageReferenceExtractor $extractor,
        ImageUsageIndex $usageIndex,
        CallerContext $callerContext,
        private readonly LiteralUrlAuditor $literalUrlAuditor
    ) {
        parent::__construct($featureChecker, $gallery, $extractor, $usageIndex, $callerContext);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Restore Media Gallery Trash';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Restore trashed assets to their original paths (Hyvä uses the delete permission for this). Refuses '
            . 'items '
            . 'whose original path is taken. A restored asset is re-indexed under a NEW asset id (asset_id); the reply '
            . 'lists the image fields still holding the old id (stale_references, previous_asset_ids) and how to '
            . 're-link them.'
            . ' ' . SharedMedia::DESCRIPTION_NOTE;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->rawProperty('trash_ids', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
                'minItems' => 1, 'description' => 'Ids from hyva_media_trash_list.'])
            ->toArray();
        $schema['required'] = ['trash_ids'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $items = $this->gallery->trashList(null, 1, 200, $this->intListArg($arguments, 'trash_ids'))['items'];
        if ($items === []) {
            return $this->softError('None of the trash ids exists.');
        }
        $restorable = [];
        $blocked = [];
        foreach ($items as $item) {
            $path = Cast::string($item['path'] ?? '');
            if ($this->gallery->pathExists($path)) {
                $blocked[] = ['trash_id' => Cast::int($item['id'] ?? 0), 'path' => $path,
                    'reason' => 'A file or folder already exists at the original path.'];
            } else {
                $restorable[Cast::int($item['id'] ?? 0)] = $path;
            }
        }
        $restored = $restorable !== [] ? $this->gallery->restore(array_keys($restorable)) : 0;
        $stale = $restorable !== [] ? $this->literalUrlAuditor->staleReferences(array_values($restorable)) : [];
        $assets = [];
        foreach ($restorable as $trashId => $path) {
            $asset = $this->gallery->getByPath($path);
            $references = $stale[$path] ?? [];
            $assets[] = [
                'trash_id' => $trashId,
                'path' => $path,
                'asset_id' => $asset['id'] ?? null,
                'asset' => $asset,
                'previous_asset_ids' => array_values(array_unique(array_map(
                    static fn (array $reference): int => Cast::int($reference['asset_id'] ?? 0),
                    $references
                ))),
                'stale_references' => $references,
            ];
        }
        $payload = ['restored' => $restored, 'assets' => $assets, 'not_restored' => $blocked];
        if ($assets !== []) {
            $payload['note'] = self::NEW_ID_NOTE;
        }
        return $this->jsonResult($payload, ['restored' => $restored]);
    }
}
