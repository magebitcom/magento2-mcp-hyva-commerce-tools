<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractTemplateList;

/**
 * `hyva_email.template.list`.
 */
class TemplateList extends AbstractTemplateList
{
    public const TOOL_NAME = 'hyva_email.template.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_template_list';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::email_template_view';
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_EMAIL;
    public const LABEL = 'email template';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä Email Templates';
    }
}
