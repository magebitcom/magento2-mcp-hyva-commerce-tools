<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractTemplateDelete;

/**
 * `hyva_email.template.delete`: confirm-gated delete of one email template.
 */
class TemplateDelete extends AbstractTemplateDelete
{
    public const TOOL_NAME = 'hyva_email.template.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_template_delete';
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_EMAIL;
    public const LABEL = 'email template';
    public const LABEL_PLURAL = 'email templates';
    /** Magento\Email\Controller\Adminhtml\Email\Template::ADMIN_RESOURCE (the Delete action extends it) */
    public const ADMIN_DELETE_RESOURCE = 'Magento_Email::template';
    public const HYVA_DELETE_RESOURCE = 'Hyva_EmailTemplates::email_template_delete';
    public const IN_USE_CONSEQUENCE = 'the store emails those config paths send can no longer load their template '
        . 'until each path is set to another template.';
    public const FORCED_AFTERMATH = 'Those config paths now name a missing template: set each to another template.';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Delete Hyvä Email Template';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete an email template permanently, with its Hyvä drafts and version history. Without "confirm": '
            . 'true nothing is deleted and the reply previews the template and where it is still used (details.usage: '
            . 'store_config = the store configuration values, any scope, that send it, e.g. '
            . 'sales_email/order/template). A template in use is deleted only with "force": true as well as '
            . '"confirm": true (' . self::IN_USE_CONSEQUENCE . ' The result then says "forced": true and repeats the '
            . 'usage); tell the user where it is used before confirming. Needs the MCP permission for this tool plus '
            . 'the admin permissions ' . self::ADMIN_DELETE_RESOURCE . ' and ' . self::HYVA_DELETE_RESOURCE . '. '
            . 'Templates apply to every store view, so a role restricted to some websites may not delete them.';
    }

    /**
     * @inheritDoc
     */
    protected function deleteTemplate(int $id): void
    {
        $this->emailGateway->deleteTemplate($id);
    }
}
