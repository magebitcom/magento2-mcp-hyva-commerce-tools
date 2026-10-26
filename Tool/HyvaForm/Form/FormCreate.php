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
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.form.create`.
 */
class FormCreate extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.form.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_create';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_save';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param FormGatewayInterface $formGateway
     * @param ContentService $contentService
     * @param FormRules $formRules
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     * @param PreviewService $previewService
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        FormGatewayInterface $formGateway,
        ContentService $contentService,
        FormRules $formRules,
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor,
        private readonly PreviewService $previewService
    ) {
        parent::__construct($featureChecker, $formGateway, $contentService, $formRules);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Create Hyvä Form';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a Form Builder form as a DRAFT: settings plus fields, built as Hyvä expects (one hyva_form '
            . 'root, '
            . 'form_step children, form_field_* components; see hyva_cms.component.schema for each field). The form '
            . 'stays disabled and unpublished until hyva_form.form.publish. Without recipient_to or webhook_url '
            . 'submissions go nowhere, so set one. identifier defaults to one derived from the title.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = $this->withSettings(Schema::object())
            ->string('identifier', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('a-z, 0-9 and _, unique per store view. Default: derived from title.'))
            ->string('layout', fn (StringBuilder $s) => $s->enum(['single_page', 'multi_step'])
                ->description('single_page (default): one step; multi_step: one page per entry in steps.'))
            ->rawProperty('fields', ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Field '
                . 'components for a single-page form: {component: "form_field_email", fields: {label, field_name, '
                . 'required, ...}}. form_row components may wrap fields.'])
            ->rawProperty('steps', ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Multi-step: '
                . '[{label, fields: [field components]}].'])
            ->string('version_name', fn (StringBuilder $s) => $s->maxLength(100))
            ->toArray();
        $schema['required'] = ['title'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $storeIds = Cast::intList($arguments['store_ids'] ?? [0]) ?: [0];
        $title = $this->requireString($arguments, 'title');
        $identifier = $this->stringArg($arguments, 'identifier')
            ?? $this->formGateway->suggestIdentifier($this->slug($title), $storeIds);
        $identifierError = $this->formGateway->identifierError($identifier, $storeIds);
        if ($identifierError !== null) {
            return $this->softError('Nothing was created: ' . $identifierError);
        }

        $document = $this->componentTree->insertNode(
            $this->componentTree->newDocument(self::CONTENT_TYPE, 0),
            $this->buildRoot($arguments)
        );
        $validation = $this->contentService->validate($document);
        $formIssues = $this->formRules->documentIssues($document);
        $errors = array_filter($formIssues, static fn (array $i): bool => $i['severity'] === 'error');
        if (!$validation['valid'] || $errors !== []) {
            return $this->softError('Nothing was created: the form content is invalid.', [
                'validation' => $validation,
                'form_issues' => $formIssues,
            ]);
        }

        $id = $this->formGateway->create(['identifier' => $identifier, 'store_ids' => $storeIds, 'is_active' => false]
            + $this->settingsFrom($arguments));
        $saved = $this->contentService->saveDraft(
            self::CONTENT_TYPE,
            $id,
            $this->componentTree->normalizeDocument($document, self::CONTENT_TYPE, $id),
            $this->stringArg($arguments, 'version_name') ?? 'Created by MCP'
        );
        $form = $this->formGateway->get($id);
        return $this->jsonResult([
            'created' => true,
            'id' => $id,
            'identifier' => $identifier,
            'draft' => $saved,
            'published' => false,
            'form_issues' => $formIssues,
            'delivery_issues' => $form !== null ? $this->formRules->deliveryIssues($form, $document) : [],
            'preview' => $this->previewService->getPreview(self::CONTENT_TYPE, $id),
            'next_step' => 'Review the preview, then call hyva_form.form.publish (which also enables the form).',
        ], ['id' => $id, 'identifier' => $identifier]);
    }

    /**
     * Root hyva_form node with its form_step children.
     *
     * @param array $arguments
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<string, mixed>
     */
    private function buildRoot(array $arguments): array
    {
        $multiStep = $this->stringArg($arguments, 'layout') === 'multi_step';
        $steps = $multiStep ? $this->arrayArg($arguments, 'steps') : [
            ['fields' => $this->arrayArg($arguments, 'fields')]
        ];
        $children = [];
        foreach ($steps as $step) {
            $step = is_array($step) ? $step : [];
            $children[] = $this->componentTree->createNode(
                'form_step',
                ['step_label' => Cast::string($step['label'] ?? '')],
                $this->draftEditor->buildNodes(is_array($step['fields'] ?? null) ? array_values($step['fields']) : [])
            );
        }
        $root = $this->componentTree->createNode('hyva_form', [], $children);
        if ($multiStep) {
            $root['componentPath'] = 'Hyva_FormBuilder::elements/multi_step_form.phtml';
        }
        return $root;
    }

    /**
     * Identifier base from a title.
     *
     * @param string $title
     * @return string
     */
    private function slug(string $title): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($title)), '_');
        return $slug !== '' ? substr($slug, 0, 60) : 'form';
    }
}
