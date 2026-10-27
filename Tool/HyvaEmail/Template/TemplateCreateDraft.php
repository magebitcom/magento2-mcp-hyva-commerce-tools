<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractEmailTool;

/**
 * `hyva_email.template.create_draft`: the Hyvä version of a core store email.
 */
class TemplateCreateDraft extends AbstractEmailTool
{
    public const TOOL_NAME = 'hyva_email.template.create_draft';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_template_create_draft';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::email_template_save';
    public const IS_WRITE = true;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Create Hyvä Email Template';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create the Hyvä CMS version of a core store email (order confirmation, invoice, shipment, credit '
            . 'memo, customer account and newsletter emails; orig_code from hyva_email.audit.coverage), with '
            . 'Hyvä\'s ready-made design as draft and content. Store emails keep sending their current template '
            . 'until this one is assigned in configuration, so creating it changes nothing customers receive. '
            . 'Reuses the existing Hyvä version when there is one.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->string('orig_code', fn (StringBuilder $s) => $s->minLength(1)
                ->description('Core template code, e.g. sales_email_order_template.'))
            ->toArray();
        $schema['required'] = ['orig_code'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $origCode = $this->requireString($arguments, 'orig_code');
        $created = $this->emailGateway->createFromCore($origCode);
        $template = $this->emailGateway->get(self::TEMPLATE_TYPE, $created['template_id']);
        return $this->jsonResult([
            'created' => $created['created'],
            'id' => $created['template_id'],
            'template' => $template,
            'live' => ($template['assigned_to'] ?? []) !== [],
            'preview' => $this->previewService->getPreview(self::TEMPLATE_TYPE, $created['template_id']),
            'next_step' => 'Edit with hyva_email.template.update_draft, check hyva_email.template.preview, then '
                . 'publish; assign it to the store email in configuration to make customers receive it.',
        ], ['id' => $created['template_id'], 'orig_code' => $origCode]);
    }
}
