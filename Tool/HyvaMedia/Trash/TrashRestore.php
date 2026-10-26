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
            . 'whose original path is taken. Restored assets are re-indexed and may get a new asset id, which is '
            . 'reported.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->rawProperty('trash_ids', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
                'minItems' => 1, 'description' => 'Ids from hyva_media.trash.list.'])
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
            if ($this->gallery->fileExists($path)) {
                $blocked[] = ['trash_id' => Cast::int($item['id'] ?? 0), 'path' => $path,
                    'reason' => 'A file already exists at the original path.'];
            } else {
                $restorable[Cast::int($item['id'] ?? 0)] = $path;
            }
        }
        $restored = $restorable !== [] ? $this->gallery->restore(array_keys($restorable)) : 0;
        $assets = [];
        foreach ($restorable as $trashId => $path) {
            $assets[] = ['trash_id' => $trashId, 'path' => $path, 'asset' => $this->gallery->getByPath($path)];
        }
        return $this->jsonResult(['restored' => $restored, 'assets' => $assets, 'not_restored' => $blocked], [
            'restored' => $restored,
        ]);
    }
}
