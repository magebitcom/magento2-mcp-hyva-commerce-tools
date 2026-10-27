<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form;

use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.form.validate`.
 */
class FormValidate extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.form.validate';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_validate';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Validate Hyvä Form';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check a form before publishing: identifier rules (format, unique per store view), component structure '
            . '(one hyva_form root, allowed children, required labels), field names (set, format, unique), regex '
            . 'patterns, option defaults, and delivery (recipients are valid addresses, webhook is dispatchable, '
            . 'reply-to and routing rules point at real fields).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withFormTarget(Schema::object())
            ->string('edition', fn (StringBuilder $s) => $s->enum(['draft', 'published'])
                ->description('Validate the draft (default) or the published content.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateForm($arguments);
        $edition = $this->stringArg($arguments, 'edition', 'draft') ?? 'draft';
        $document = $edition === 'published'
            ? $this->contentService->getDocuments(self::CONTENT_TYPE, $form['id'])['published']
            : $this->contentService->getWorkingDocument(self::CONTENT_TYPE, $form['id']);
        if ($document === null) {
            return $this->softError(sprintf('Form "%s" has no %s content.', $form['identifier'], $edition));
        }
        $identifierError = $this->formGateway->identifierError($form['identifier'], $form['store_ids'], $form['id']);
        $structure = $this->contentService->validate($document);
        $formIssues = $this->formRules->documentIssues($document);
        $delivery = $this->formRules->deliveryIssues($form, $document);
        $errors = count(array_filter(
            array_merge($structure['issues'], $formIssues, $delivery),
            static fn (array $i): bool => $i['severity'] === 'error'
        )) + ($identifierError !== null ? 1 : 0) + ($structure['schema_error'] !== null ? 1 : 0);
        return $this->jsonResult([
            'id' => $form['id'],
            'edition' => $edition,
            'valid' => $errors === 0,
            'identifier_error' => $identifierError,
            'structure' => $structure,
            'form_issues' => $formIssues,
            'delivery_issues' => $delivery,
        ], ['id' => $form['id'], 'errors' => $errors]);
    }
}
