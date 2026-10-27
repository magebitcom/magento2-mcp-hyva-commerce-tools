<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\LinkedProducts\AbstractLinkedTool;

class GroupValidate extends AbstractLinkedTool
{
    public const TOOL_NAME = 'hyva_linked.group.validate';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_linked_group_validate';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Validate Linked Product Group';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check a new or changed Hyvä Linked Products group without saving: errors Hyvä would refuse '
            . '(fewer than 2 products, unknown SKUs, products already in another group, attributes that cannot '
            . 'link, duplicate name) and storefront warnings (members not shown, members shoppers cannot tell '
            . 'apart). Same inputs as hyva_linked.group.save.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withGroupChange(Schema::object())->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeLinked(array $arguments): ToolResultInterface
    {
        $change = $this->resolveChange($arguments);
        $valid = $this->errors($change['issues']) === [];
        return $this->jsonResult(['valid' => $valid] + $change, ['valid' => $valid]);
    }
}
