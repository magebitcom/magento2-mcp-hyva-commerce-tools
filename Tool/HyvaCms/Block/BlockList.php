<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block;

use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityList;

/**
 * `hyva_cms.block.list` MCP tool.
 */
class BlockList extends AbstractEntityList
{
    public const TOOL_NAME = 'hyva_cms.block.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_block_list';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::block_view';
    public const ENTITY_TYPE = 'cms_block';
    public const ENTITY_LABEL = 'Block';
}
