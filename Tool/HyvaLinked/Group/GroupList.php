<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\LinkedProducts\AbstractLinkedTool;

class GroupList extends AbstractLinkedTool
{
    public const TOOL_NAME = 'hyva_linked.group.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_linked_group_list';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Linked Product Groups';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List Hyvä Linked Products groups: sets of separate products (e.g. one t-shirt per color) that '
            . 'shoppers switch between on the product page. Shows each group\'s link attributes and member SKUs, '
            . 'plus the attributes that can link a group. Filter by name or by a member SKU.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('search', fn (StringBuilder $s) => $s->description('Name contains.'))
            ->string('sku', fn (StringBuilder $s) => $s->description('Only the group this product is in.'))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeLinked(array $arguments): ToolResultInterface
    {
        $result = $this->linkedGroups->getList(
            $this->stringArg($arguments, 'search'),
            $this->stringArg($arguments, 'sku'),
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult(
            $result + ['linkable_attributes' => $this->linkedGroups->linkableAttributes()],
            ['total' => $result['total']]
        );
    }
}
