<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page;

use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityDelete;

/**
 * `hyva_cms.page.delete` MCP tool.
 */
class PageDelete extends AbstractEntityDelete
{
    public const TOOL_NAME = 'hyva_cms.page.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_page_delete';
    public const ENTITY_TYPE = 'cms_page';
    public const ENTITY_LABEL = 'Page';
    public const ENTITY_LABEL_PLURAL = 'Pages';
    /** Magento\Cms\Controller\Adminhtml\Page\Delete::ADMIN_RESOURCE */
    public const ADMIN_DELETE_RESOURCE = 'Magento_Cms::page_delete';
    public const HYVA_DELETE_RESOURCE = 'Hyva_Cms::page_delete';
}
