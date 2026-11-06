<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Trash;

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
        . 'Image fields that held the old id are stale: they still render (Hyvä falls back to the stored path) but '
        . 'lose the link to the asset. Find them with hyva_media_audit_literal_urls (category stale_asset_id) and '
        . 're-link them with hyva_media_image_replace using the same path as old_path and new_path and confirm '
        . 'true (saves drafts with the new id; publish them).';

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
            . 'whose original path is taken. A restored asset is re-indexed under a NEW asset id (asset_id); find '
            . 'image fields still holding the old id with hyva_media_audit_literal_urls (stale_asset_id).'
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
        $assets = [];
        foreach ($restorable as $trashId => $path) {
            $asset = $this->gallery->getByPath($path);
            $assets[] = [
                'trash_id' => $trashId,
                'path' => $path,
                'asset_id' => $asset['id'] ?? null,
                'asset' => $asset,
            ];
        }
        $payload = ['restored' => $restored, 'assets' => $assets, 'not_restored' => $blocked];
        if ($assets !== []) {
            $payload['note'] = self::NEW_ID_NOTE;
        }
        return $this->jsonResult($payload, ['restored' => $restored]);
    }
}
