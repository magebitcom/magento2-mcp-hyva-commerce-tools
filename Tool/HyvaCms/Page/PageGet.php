<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page;

use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityGet;

/**
 * `hyva_cms.page.get` MCP tool.
 */
class PageGet extends AbstractEntityGet
{
    public const TOOL_NAME = 'hyva_cms.page.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_page_get';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::page_view';
    public const ENTITY_TYPE = 'cms_page';
    public const ENTITY_LABEL = 'Page';
}
