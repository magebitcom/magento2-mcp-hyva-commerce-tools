<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block;

use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityUpdate;

/**
 * `hyva_cms.block.update` MCP tool.
 */
class BlockUpdate extends AbstractEntityUpdate
{
    public const TOOL_NAME = 'hyva_cms.block.update';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_block_update';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::block_save';
    public const ENTITY_TYPE = 'cms_block';
    public const ENTITY_LABEL = 'Block';
}
