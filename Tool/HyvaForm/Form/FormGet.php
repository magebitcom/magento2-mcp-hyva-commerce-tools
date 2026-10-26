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
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.form.get`.
 */
class FormGet extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.form.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_get';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param FormGatewayInterface $formGateway
     * @param ContentService $contentService
     * @param FormRules $formRules
     * @param StoreScopeGuard $storeScopeGuard
     * @param PreviewService $previewService
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        FormGatewayInterface $formGateway,
        ContentService $contentService,
        FormRules $formRules,
        StoreScopeGuard $storeScopeGuard,
        private readonly PreviewService $previewService
    ) {
        parent::__construct($featureChecker, $formGateway, $contentService, $formRules, $storeScopeGuard);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Hyvä Form';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'One form: settings (recipients, subject, webhook, CAPTCHA, store views), its fields in the draft (uid, '
            . 'type, '
            . 'field_name, label, required), whether the draft differs from the published form, preview and published '
            . 'URLs per store view. include_document returns the full draft component tree for draft edits.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withFormTarget(Schema::object())
            ->boolean('include_document', fn (BooleanBuilder $b) => $b
                ->description('Also return the full draft document (needed for draft operations by uid).'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateForm($arguments);
        $documents = $this->contentService->getDocuments(self::CONTENT_TYPE, $form['id']);
        $draft = $documents['draft'] ?? $this->contentService->getWorkingDocument(self::CONTENT_TYPE, $form['id']);
        $fields = array_map(
            static fn (array $f): array => array_diff_key($f, ['node' => true]),
            $this->formRules->fields($draft)
        );
        $result = [
            'settings' => $form,
            'draft_fields' => $fields,
            'draft_version_uid' => is_array($draft['version'] ?? null) ? ($draft['version']['uid'] ?? null) : null,
            'preview' => $this->previewService->getPreview(self::CONTENT_TYPE, $form['id']),
        ];
        if ($this->boolArg($arguments, 'include_document')) {
            $result['draft_document'] = $draft;
        }
        return $this->jsonResult($result, ['id' => $form['id']]);
    }
}
