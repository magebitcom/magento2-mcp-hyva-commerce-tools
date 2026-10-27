<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\LinkedProducts\AbstractLinkedTool;

class GroupSave extends AbstractLinkedTool
{
    public const TOOL_NAME = 'hyva_linked.group.save';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_linked_group_save';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_LinkedProducts::group_save';
    public const IS_WRITE = true;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Save Linked Product Group';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a Hyvä Linked Products group or change one (name, link attributes, members by SKU; '
            . 'add_skus / remove_skus edit the current members). Goes live on the product pages at once. Without '
            . 'confirm=true it only validates and shows the result; refused while there are errors. A product can '
            . 'be in one group only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withGroupChange(Schema::object())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Save after reviewing the check.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeLinked(array $arguments): ToolResultInterface
    {
        $change = $this->resolveChange($arguments);
        $errors = $this->errors($change['issues']);
        if ($errors !== []) {
            return $this->softError('Nothing was saved: fix these first.', ['issues' => $errors]);
        }
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(
                $change['group_id'] === null ? 'create_linked_group' : 'update_linked_group',
                [$change]
            );
        }
        $groupId = $this->linkedGroups->save(
            $change['group_id'],
            $change['name'],
            $change['attribute_codes'],
            $change['product_ids']
        );
        return $this->jsonResult([
            'saved' => true,
            'created' => $change['group_id'] === null,
            'group' => $this->linkedGroups->get($groupId),
            'warnings' => $change['issues'],
            'note' => 'Product pages of the old and new members were refreshed in the page cache.',
        ], ['id' => $groupId, 'created' => $change['group_id'] === null]);
    }
}
