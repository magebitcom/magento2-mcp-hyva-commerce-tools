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

class GroupGet extends AbstractLinkedTool
{
    public const TOOL_NAME = 'hyva_linked.group.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_linked_group_get';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Linked Product Group';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Read one Hyvä Linked Products group (by id, name or a member SKU): its link attributes, each '
            . 'member with its attribute values and whether the storefront shows it, and storefront problems '
            . '(hidden members, members with the same values).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('name', fn (StringBuilder $s) => $s->maxLength(255))
            ->string('sku', fn (StringBuilder $s) => $s->description('A member SKU.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeLinked(array $arguments): ToolResultInterface
    {
        $id = $this->intArg($arguments, 'id');
        $name = $this->stringArg($arguments, 'name');
        $sku = $this->stringArg($arguments, 'sku');
        if ($id === null && $name !== null) {
            $id = $this->linkedGroups->findIdByName($name);
        } elseif ($id === null && $sku !== null) {
            $id = $this->linkedGroups->getList(null, $sku, 1, 1)['items'][0]['id'] ?? null;
        }
        if ($id === null) {
            return $this->softError('No such group. Pass id, name or a member sku (hyva_linked.group.list).');
        }
        $group = $this->linkedGroups->get($id);
        $codes = array_column($group['attributes'], 'code');
        $check = $this->linkedGroups->check(
            $id,
            $group['name'],
            $codes,
            array_values($this->linkedGroups->productIdsBySkus($group['skus']))
        );
        return $this->jsonResult($group + [
            'members' => $check['members'],
            'storefront_issues' => array_values(array_filter(
                $check['issues'],
                static fn (array $i): bool => $i['severity'] === 'warning'
            )),
        ], ['id' => $id]);
    }
}
