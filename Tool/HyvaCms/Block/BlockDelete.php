<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block;

use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityDelete;

/**
 * `hyva_cms.block.delete` MCP tool.
 */
class BlockDelete extends AbstractEntityDelete
{
    public const TOOL_NAME = 'hyva_cms.block.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_block_delete';
    public const ENTITY_TYPE = 'cms_block';
    public const ENTITY_LABEL = 'Block';
    /** Magento\Cms\Controller\Adminhtml\Block::ADMIN_RESOURCE (Magento has no separate block delete permission) */
    public const ADMIN_DELETE_RESOURCE = 'Magento_Cms::block';
    public const HYVA_DELETE_RESOURCE = 'Hyva_Cms::block_delete';
}
