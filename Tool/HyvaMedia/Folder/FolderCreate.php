<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Folder;

use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

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
        return 'Create a Media Gallery folder under wysiwyg/ (parents are created too).';
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
        $this->gallery->createFolder($path);
        return $this->jsonResult(['created' => true, 'path' => $path], ['path' => $path]);
    }
}
