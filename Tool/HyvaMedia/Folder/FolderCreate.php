<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Folder;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Media\SharedMedia;

/**
 * `hyva_media.folder.create`.
 */
class FolderCreate extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.folder.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_folder_create';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::folder_save';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MediaGalleryGatewayInterface $gallery
     * @param ImageReferenceExtractor $extractor
     * @param ImageUsageIndex $usageIndex
     * @param CallerContext $callerContext
     * @param MediaFileInfo $fileInfo
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MediaGalleryGatewayInterface $gallery,
        ImageReferenceExtractor $extractor,
        ImageUsageIndex $usageIndex,
        CallerContext $callerContext,
        private readonly MediaFileInfo $fileInfo
    ) {
        parent::__construct($featureChecker, $gallery, $extractor, $usageIndex, $callerContext);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Create Media Gallery Folder';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a Media Gallery folder under wysiwyg/ (parents are created too).'
            . ' ' . SharedMedia::DESCRIPTION_NOTE;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->string('path', fn (StringBuilder $s) => $s->minLength(1)->description('e.g. wysiwyg/campaigns/spring.'))
            ->toArray();
        $schema['required'] = ['path'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $path = trim($this->requireString($arguments, 'path'), '/');
        $error = $this->folderError($path);
        if ($error !== null) {
            return $this->softError($error);
        }
        if ($this->fileInfo->resolvesOutsideMedia($path)) {
            return $this->softError(
                sprintf(
                    'Folder "%s" resolves outside pub/media through a symbolic link; no folder was created.',
                    $path
                ),
                ['paths_outside_media' => [$path]]
            );
        }
        $this->gallery->createFolder($path);
        return $this->jsonResult(['created' => true, 'path' => $path], ['path' => $path]);
    }
}
