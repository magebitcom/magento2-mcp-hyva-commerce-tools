<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractTemplateGet;

/**
 * `hyva_email.newsletter.get`.
 */
class NewsletterGet extends AbstractTemplateGet
{
    public const TOOL_NAME = 'hyva_email.newsletter.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_newsletter_get';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::newsletter_template_view';
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_NEWSLETTER;
    public const LABEL = 'newsletter template';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Hyvä Newsletter Template';
    }
}
