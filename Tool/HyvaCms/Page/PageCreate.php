<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page;

use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityCreate;

/**
 * `hyva_cms.page.create` MCP tool.
 */
class PageCreate extends AbstractEntityCreate
{
    public const TOOL_NAME = 'hyva_cms.page.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_page_create';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::page_save';
    public const ENTITY_TYPE = 'cms_page';
    public const ENTITY_LABEL = 'Page';

    /**
     * @inheritDoc
     */
    protected function entityFields(): array
    {
        return [
            'page_layout' => 'Page layout, e.g. 1column or cms-full-width. Default: the store default CMS layout'
                . ' (web/default_layouts/default_cms_layout).',
            'content_heading' => 'Heading shown above the content.',
            'meta_title' => 'SEO title.',
            'meta_keywords' => 'SEO keywords.',
            'meta_description' => 'SEO description.',
        ];
    }
}
