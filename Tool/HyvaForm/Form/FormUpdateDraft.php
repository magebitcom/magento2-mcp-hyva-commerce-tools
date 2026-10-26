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
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.form.update_draft`.
 */
class FormUpdateDraft extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.form.update_draft';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_update_draft';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_save';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param FormGatewayInterface $formGateway
     * @param ContentService $contentService
     * @param FormRules $formRules
     * @param DraftEditor $draftEditor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        FormGatewayInterface $formGateway,
        ContentService $contentService,
        FormRules $formRules,
        private readonly DraftEditor $draftEditor
    ) {
        parent::__construct($featureChecker, $formGateway, $contentService, $formRules);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Update Hyvä Form Draft';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Edit a form: operations change the DRAFT content (same operations as hyva_cms.draft.update: add / '
            . 'remove / '
            . 'move / set_field / set_fields / replace_node; uids from hyva_form.form.get with include_document). '
            . 'Settings (recipients, subject, webhook, title, store views, CAPTCHA) apply IMMEDIATELY. Publish with '
            . 'hyva_form.form.publish. Problems Hyvä would refuse on publish come back in form_issues.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = $this->withSettings($this->withFormTarget(Schema::object()))
            ->string('new_identifier', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('Rename the identifier (a-z, 0-9, _); embeds using the old one stop rendering.'))
            ->rawProperty('operations', ['type' => 'array', 'items' => ['type' => 'object'],
                'description' => 'Draft operations: ' . implode(', ', array_keys(ContentSchema::OPERATIONS)) . '.'])
            ->string('version_name', fn (StringBuilder $s) => $s->maxLength(100))
            ->string('base_version_uid', fn (StringBuilder $s) => $s->description('Optional: draft version uid you '
                . 'read (draft_version_uid of hyva_form.form.get); refused with status 409 when it changed since.'))
            ->toArray();
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateForm($arguments);
        $result = ['id' => $form['id'], 'published' => false];
        $settings = $this->settingsFrom($arguments);
        $candidate = $form;
        foreach (['recipient_to', 'recipient_cc', 'recipient_bcc'] as $key) {
            if (array_key_exists($key, $settings)) {
                $candidate[$key] = implode(',', $this->formRules->addresses($settings[$key]));
            }
        }
        foreach (['webhook_url', 'webhook_format'] as $key) {
            if (array_key_exists($key, $settings)) {
                $candidate[$key] = Cast::string($settings[$key]);
            }
        }
        $settingIssues = $this->formRules->deliveryIssues($candidate, null);
        // A form that already has a delivery problem must stay editable: refuse only problems of settings this call
        // changes (recipients by address, webhook issues whenever the webhook is touched).
        $existing = array_column($this->formRules->deliveryIssues($form, null), 'message');
        $webhookTouched = array_intersect_key($settings, array_flip(['webhook_url', 'webhook_format'])) !== [];
        $blocking = array_values(array_filter($settingIssues, static fn (array $i): bool => $i['severity'] === 'error'
            && (!in_array($i['message'], $existing, true)
                || (
                    $webhookTouched && in_array(
                        $i['code'],
                        ['webhook_not_dispatchable', 'invalid_webhook_format'],
                        true
                    )
                ))
            && in_array(
                $i['code'],
                ['invalid_recipient', 'webhook_not_dispatchable', 'invalid_webhook_format'],
                true
            )));
        if ($blocking !== []) {
            // These settings are live immediately (no draft), so refuse them rather than report afterwards.
            return $this->softError('Nothing was changed: fix the delivery settings first.', ['issues' => $blocking]);
        }
        unset($settings['identifier']);
        $newIdentifier = $this->stringArg($arguments, 'new_identifier');
        if ($newIdentifier !== null || isset($settings['store_ids'])) {
            // Check the identifier against the store views the form will have after this call.
            $storeIds = isset($settings['store_ids'])
                ? (Cast::intList($settings['store_ids']) ?: [0])
                : $form['store_ids'];
            $error = $this->formGateway->identifierError($newIdentifier ?? $form['identifier'], $storeIds, $form['id']);
            if ($error !== null) {
                return $this->softError('Nothing was changed: ' . $error);
            }
        }
        if ($newIdentifier !== null) {
            $settings['identifier'] = $newIdentifier;
        }

        $operations = $this->arrayArg($arguments, 'operations');
        if ($operations !== []) {
            $document = $this->contentService->getWorkingDocument(self::CONTENT_TYPE, $form['id']);
            $applied = $this->draftEditor->apply($document, $operations);
            $result['form_issues'] = $this->formRules->documentIssues($applied['document']);
            $result['draft'] = $this->contentService->saveDraft(
                self::CONTENT_TYPE,
                $form['id'],
                $applied['document'],
                $this->stringArg($arguments, 'version_name'),
                $this->stringArg($arguments, 'base_version_uid')
            );
            $result['applied_operations'] = $applied['applied'];
        }
        if ($settings !== []) {
            $result['settings_changed'] = $this->formGateway->update($form['id'], $settings);
        }
        if ($operations === [] && $settings === []) {
            return $this->softError('Pass operations and/or settings to change.');
        }
        $updated = $this->formGateway->get($form['id']) ?? $form;
        $result['delivery_issues'] = $this->formRules->deliveryIssues(
            $updated,
            $this->contentService->getWorkingDocument(self::CONTENT_TYPE, $form['id'])
        );
        return $this->jsonResult($result, ['id' => $form['id']]);
    }
}
