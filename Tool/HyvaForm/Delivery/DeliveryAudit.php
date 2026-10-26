<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Delivery;

use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.delivery.audit`.
 */
class DeliveryAudit extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.delivery.audit';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_delivery_audit';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Hyvä Form Delivery';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check where submissions of every form (or one) end up: forms that send nowhere, invalid recipient '
            . 'addresses, webhooks Hyvä would refuse or that are not HTTPS, reply-to and routing rules pointing at '
            . 'missing fields, CAPTCHA off, enabled-but-unpublished forms. Hyvä stores no submissions, so a broken '
            . 'destination loses them.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withFormTarget(Schema::object())->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $total = 1;
        if (isset($arguments['id']) || isset($arguments['identifier'])) {
            $forms = [$this->locateForm($arguments)];
        } else {
            $list = $this->formGateway->getList(null, null, 1, 200);
            $forms = $list['items'];
            $total = $list['total'];
        }
        $report = [];
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($forms as $form) {
            $documents = $this->contentService->getDocuments(self::CONTENT_TYPE, $form['id']);
            $issues = $this->formRules->deliveryIssues($form, $documents['published'] ?? $documents['draft']);
            foreach ($issues as $issue) {
                $counts[$issue['severity']] = ($counts[$issue['severity']] ?? 0) + 1;
            }
            $report[] = [
                'id' => $form['id'],
                'identifier' => $form['identifier'],
                'title' => $form['title'],
                'is_active' => $form['is_active'],
                'recipients' => $this->formRules->addresses($form['recipient_to']),
                'webhook_url' => $form['webhook_url'],
                'issues' => $issues,
            ];
        }
        return $this->jsonResult([
            'forms_checked' => count($report),
            'forms_total' => $total,
            'truncated' => $total > count($report),
            'counts' => $counts,
            'forms' => $report,
        ], $counts);
    }
}
