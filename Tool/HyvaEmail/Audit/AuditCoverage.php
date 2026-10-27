<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Audit;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractEmailTool;

/**
 * `hyva_email.audit.coverage`: which store emails are designed in Hyvä CMS.
 */
class AuditCoverage extends AbstractEmailTool
{
    public const TOOL_NAME = 'hyva_email.audit.coverage';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_audit_coverage';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Hyvä Email Coverage';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'For every core store email Hyvä ships a design for (orders, invoices, shipments, credit memos, '
            . 'customer account, newsletter subscription): which template each scope sends, and whether that is a '
            . 'published Hyvä CMS design. Lists the emails still on the classic Magento template and whether a '
            . 'Hyvä version already exists to assign.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $definitions = [];
        foreach ($this->emailGateway->coreDefinitions() as $definition) {
            $definitions[$definition['orig_code']] = $definition;
        }
        $emails = [];
        $counts = ['hyva' => 0, 'classic' => 0];
        $replacementEnabled = $this->emailGateway->isEmailReplacementEnabled();
        $templates = [];
        foreach ($this->emailGateway->configUsage() as $usage) {
            $scopes = [];
            $default = [['scope' => 'default', 'scope_id' => 0, 'value' => '', 'template_id' => 0]];
            foreach ($usage['scopes'] ?: $default as $scope) {
                $template = null;
                if ($scope['template_id'] > 0) {
                    // Many scopes share a template: load each one once.
                    $templates[$scope['template_id']] ??= $this->emailGateway->get(
                        self::TEMPLATE_TYPE,
                        $scope['template_id']
                    );
                    $template = $templates[$scope['template_id']];
                }
                $hyva = $replacementEnabled && $template !== null && $template['hyva_enabled']
                    && $template['has_published'];
                $scopes[] = $scope + [
                    'sends' => $template !== null ? $template['code'] : 'classic Magento file template',
                    'hyva_design_live' => $hyva,
                ];
                $counts[$hyva ? 'hyva' : 'classic']++;
            }
            $available = $definitions[$usage['orig_code']]['template_id'] ?? 0;
            $emails[] = [
                'email' => trim(str_replace('Hyvä CMS —', '', $usage['name'])),
                'orig_code' => $usage['orig_code'],
                'config_path' => $usage['config_path'],
                'scopes' => $scopes,
                'hyva_template_id' => $available > 0 ? $available : null,
                'next_step' => $available > 0
                    ? null
                    : 'Create it with hyva_email.template.create_draft (orig_code ' . $usage['orig_code'] . ').',
            ];
        }
        return $this->jsonResult([
            'replacement_enabled' => $replacementEnabled,
            'note' => $replacementEnabled ? null : 'hyva_email_templates/email_template/enabled is off, so no Hyvä '
                . 'design is used when emails are sent.',
            'counts' => $counts,
            'emails' => $emails,
        ], $counts);
    }
}
