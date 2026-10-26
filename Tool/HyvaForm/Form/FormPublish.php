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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.form.publish`.
 */
class FormPublish extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.form.publish';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_publish';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_publish';
    public const IS_WRITE = true;

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
        return 'Publish Hyvä Form';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Make the form LIVE: publish its current draft (or a version_id from hyva_form.version.list). Hyvä also '
            . 'enables the form. Refused (status 422 with field_errors) when field names repeat, a pattern does not '
            . 'compile or a default is not an option; the same checks run here first.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withFormTarget(Schema::object())
            ->integer('version_id', fn (IntegerBuilder $i) => $i->minimum(1)
                ->description('Publish this version-history item instead of the current draft.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateFormForWrite($arguments);
        $versionId = $this->intArg($arguments, 'version_id');
        if ($versionId === null) {
            $draft = $this->contentService->getWorkingDocument(self::CONTENT_TYPE, $form['id']);
            $issues = $this->formRules->documentIssues($draft);
            $errors = array_values(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'error'));
            if ($errors !== []) {
                return $this->softError('Not published: fix these first.', ['status' => 422, 'form_issues' => $errors]);
            }
        }
        $published = $this->contentService->publish(self::CONTENT_TYPE, $form['id'], $versionId);
        $updated = $this->formGateway->get($form['id']) ?? $form;
        return $this->jsonResult([
            'published' => true,
            'id' => $form['id'],
            'identifier' => $form['identifier'],
            'result' => $published,
            'is_active' => $updated['is_active'],
            'delivery_issues' => $this->formRules->deliveryIssues(
                $updated,
                $this->contentService->getDocuments(self::CONTENT_TYPE, $form['id'])['published']
            ),
            'published_urls' => $this->previewService->getPublishedUrls(self::CONTENT_TYPE, $form['id']),
            'embed' => sprintf(
                'Place it with the hyva_form_widget component (form_identifier "%s").',
                $form['identifier']
            ),
        ], ['id' => $form['id']]);
    }
}
