<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractTemplateDelete;

/**
 * `hyva_email.newsletter.delete`: confirm-gated delete of one newsletter template.
 *
 * Hyvä has no newsletter delete permission, so it needs Magento's newsletter template permission (what the admin
 * delete action checks) next to its own resource.
 */
class NewsletterDelete extends AbstractTemplateDelete
{
    public const TOOL_NAME = 'hyva_email.newsletter.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_newsletter_delete';
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_NEWSLETTER;
    public const LABEL = 'newsletter template';
    public const LABEL_PLURAL = 'newsletter templates';
    /** Magento\Newsletter\Controller\Adminhtml\Template::ADMIN_RESOURCE (the Delete action extends it) */
    public const ADMIN_DELETE_RESOURCE = 'Magento_Newsletter::template';
    public const IN_USE_CONSEQUENCE = 'Magento deletes the queued newsletters with it, so they are never sent.';
    public const FORCED_AFTERMATH = 'Magento deleted those queued newsletters with it; they will not be sent.';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Delete Hyvä Newsletter Template';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a newsletter template permanently, with its Hyvä drafts and version history. Without '
            . '"confirm": true nothing is deleted and the reply previews the template and where it is still used '
            . '(details.usage: queue = newsletters queued from it that are not sent yet, queue_history = how many '
            . 'sent or cancelled queue entries Magento deletes with it; history is not usage). A template in use is '
            . 'deleted only with "force": true as well as "confirm": true (' . self::IN_USE_CONSEQUENCE . ' The '
            . 'result then says "forced": true and repeats the usage); tell the user where it is used before '
            . 'confirming. Needs the MCP permission for this tool plus the admin permission '
            . self::ADMIN_DELETE_RESOURCE . '. Templates apply to every store view, so a role restricted to some '
            . 'websites may not delete them.';
    }

    /**
     * @inheritDoc
     */
    protected function deleteTemplate(int $id): void
    {
        $this->emailGateway->deleteNewsletter($id);
    }
}
