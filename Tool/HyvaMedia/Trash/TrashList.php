<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Trash;

use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.trash.list`.
 */
class TrashList extends AbstractMediaGalleryTool
{
    public const TOOL_NAME = 'hyva_media.trash.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_trash_list';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Media Gallery Trash';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Assets in Hyvä\'s Media Gallery Trash: trash id, original path, size, who trashed it and when, plus '
            . 'the '
            . 'retention period after which cron deletes them for good.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('search', fn (StringBuilder $s) => $s->description('Path contains.'))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeGallery(array $arguments): ToolResultInterface
    {
        $result = $this->gallery->trashList(
            $this->stringArg($arguments, 'search'),
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
