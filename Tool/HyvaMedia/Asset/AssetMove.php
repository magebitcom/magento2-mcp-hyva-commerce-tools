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
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Media\SharedMedia;

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
        return 'Move Media Gallery Assets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Move assets to another folder under wysiwyg/ (Hyvä\'s MediaMover; asset ids stay the same, files '
            . 'whose name exists in the target are skipped). Content that references an asset by id keeps working; '
            . 'content that references the old path does not, so a move always needs "confirm": true: without it '
            . 'nothing changes and the reply lists each asset with its usages (affected) and total. Pages, '
            . 'blocks, menus, products and categories outside your role\'s websites/store views are left out of '
            . 'them and counted in usages_hidden_by_permissions.'
            . ' ' . SharedMedia::DESCRIPTION_NOTE;
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
            ->boolean('confirm', fn (BooleanBuilder $b) => $b
                ->description('Always required; call without it first to get the preview.'))
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
            $scoped = $this->scopeFilter->filterAssetUsages($this->usages($assets));
            foreach ($assets as $asset) {
                $affected[] = $this->scopeFilter->withHidden(
                    ['asset' => $asset, 'used_in' => $scoped['visible'][$asset['id']]],
                    'usages_hidden_by_permissions',
                    $scoped['hidden'][$asset['id']]
                );
            }
            return $this->confirmationRequired('move_assets', $affected, [
                'target_folder' => $target,
                'note' => 'Usages by literal path break after the move; those by asset id keep working '
                    . '(hyva_media_audit_literal_urls lists the literal ones).',
            ]);
        }
        $result = $this->gallery->move(array_column($assets, 'id'), $target);
        return $this->jsonResult($result + ['target_folder' => $target], $result);
    }
}
