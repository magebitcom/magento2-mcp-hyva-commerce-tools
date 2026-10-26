<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractTemplatePublish;

/**
 * `hyva_email.newsletter.publish`.
 */
class NewsletterPublish extends AbstractTemplatePublish
{
    public const TOOL_NAME = 'hyva_email.newsletter.publish';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_newsletter_publish';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::newsletter_template_publish';
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_NEWSLETTER;
    public const LABEL = 'newsletter template';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Publish Hyvä Newsletter Template';
    }
}
