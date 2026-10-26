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
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.asset.trash`.
 */
class AssetTrash extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.asset.trash';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_asset_trash';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_delete';
    public const IS_WRITE = true;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Trash Media Gallery Assets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Move assets to Hyvä\'s Trash (restorable with hyva_media.trash.restore during the retention period, '
            . 'default 7 days; then purged by cron). Without confirm=true you get where each asset is used first. A '
            . 'restored asset may get a new id, so id references to it stop resolving.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->rawProperty('ids', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1,
                'maxItems' => 100])
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Trash after reviewing the preview.'))
            ->toArray();
        $schema['required'] = ['ids'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $assets = $this->gallery->getByIds($this->intListArg($arguments, 'ids'));
        if ($assets === []) {
            return $this->softError('None of the ids is a Media Gallery asset.');
        }
        $affected = [];
        $inUse = 0;
        foreach ($assets as $asset) {
            $usage = $this->usage($asset);
            $inUse += ($usage['hyva_and_catalog'] !== [] || $usage['magento_tracked'] !== []) ? 1 : 0;
            $affected[] = ['asset' => $asset, 'used_in' => $usage];
        }
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('trash_assets', [
                'assets' => $affected,
                'in_use' => $inUse,
                'note' => $inUse > 0 ? 'Assets still in use show as broken images until replaced or restored.' : null,
            ]);
        }
        $trashed = $this->gallery->trash(array_column($assets, 'id'), $this->adminUserId());
        return $this->jsonResult(['trashed' => $trashed, 'in_use' => $inUse], ['trashed' => $trashed]);
    }
}
