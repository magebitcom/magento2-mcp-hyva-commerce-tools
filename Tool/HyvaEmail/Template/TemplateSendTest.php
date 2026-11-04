<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractEmailTool;
use Magento\Framework\Exception\MailException;

/**
 * `hyva_email.template.send_test`: real email, so it is a confirmed write.
 */
class TemplateSendTest extends AbstractEmailTool
{
    public const TOOL_NAME = 'hyva_email.template.send_test';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_template_send_test';
    /**
     * Hyvä has no send-test resource and its admin button only needs email_template_view; a test send is an
     * outbound write, so MCP asks for the template write verb (Save draft) instead.
     */
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::email_template_save';
    public const IS_WRITE = true;
    public const CHANGES_TEMPLATES = false;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Send Hyvä Email Template Test';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Send the PUBLISHED Hyvä version of an email template to one address with Hyvä\'s sample customer '
            . 'and order, like the admin "Send test" button. Sends a real email; nothing else changes. '
            . 'When the store cannot send mail, it answers sent: false with status "transport_error".';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = $this->withTarget(Schema::object())
            ->string('recipient', fn (StringBuilder $s) => $s->minLength(3)->maxLength(254))
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->toArray();
        $schema['required'] = ['recipient'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $recipient = $this->requireString($arguments, 'recipient');
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return $this->softError(sprintf('"%s" is not a valid email address.', $recipient));
        }
        $template = $this->locateTemplate($arguments);
        $storeId = $this->storeScopeGuard->resolveStoreId($this->intArg($arguments, 'store_id'));
        try {
            $this->emailGateway->sendTest($template['id'], $recipient, $storeId);
        } catch (MailException $e) {
            // The store's mail transport failed (no SMTP, relay refused ...). Answer a soft error so a client does
            // not read it as a failed tool or a missing template; any other exception still propagates.
            $message = $e->getMessage();
            return $this->softError(
                $message,
                [
                    'sent' => false,
                    'status' => 'transport_error',
                    'message' => $message,
                    'id' => $template['id'],
                    'recipient' => $recipient,
                ],
                'transport_error'
            );
        }
        return $this->jsonResult(
            ['sent' => true, 'id' => $template['id'], 'recipient' => $recipient],
            ['id' => $template['id']]
        );
    }
}
