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
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.asset.move`.
 */
class AssetMove extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.asset.move';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_asset_move';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_save';
    public const IS_WRITE = true;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Move Media Gallery Assets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Move assets to another folder under wysiwyg/ (Hyvä\'s MediaMover; asset ids stay the same, files whose '
            . 'name exists in the target are skipped). Content that references an asset by id keeps working; '
            . 'content that references the old path does not, so without confirm=true you get the affected usages '
            . 'first.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->rawProperty('ids', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1,
                'maxItems' => 100])
            ->string('target_folder', fn (StringBuilder $s) => $s->minLength(1)->description('e.g. wysiwyg/campaigns.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Move after reviewing the preview.'))
            ->toArray();
        $schema['required'] = ['ids', 'target_folder'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $target = trim($this->requireString($arguments, 'target_folder'), '/');
        $error = $this->folderError($target);
        if ($error !== null) {
            return $this->softError($error);
        }
        $assets = $this->gallery->getByIds($this->intListArg($arguments, 'ids'));
        if ($assets === []) {
            return $this->softError('None of the ids is a Media Gallery asset.');
        }
        if (!$this->boolArg($arguments, 'confirm')) {
            $affected = [];
            $usages = $this->usages($assets);
            foreach ($assets as $asset) {
                $affected[] = ['asset' => $asset, 'used_in' => $usages[$asset['id']]];
            }
            return $this->confirmationRequired('move_assets', [
                'target_folder' => $target,
                'assets' => $affected,
                'note' => 'Usages by literal path break after the move; those by asset id keep working '
                    . '(hyva_media.audit.literal_urls lists the literal ones).',
            ]);
        }
        $result = $this->gallery->move(array_column($assets, 'id'), $target);
        return $this->jsonResult($result + ['target_folder' => $target], $result);
    }
}
