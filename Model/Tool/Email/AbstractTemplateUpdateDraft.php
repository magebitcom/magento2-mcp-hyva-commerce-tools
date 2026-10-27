<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Email;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;

/**
 * Edit the Hyvä CMS draft of an email or newsletter template; settings apply immediately.
 */
abstract class AbstractTemplateUpdateDraft extends AbstractEmailTool
{
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param EmailGatewayInterface $emailGateway
     * @param ContentService $contentService
     * @param PreviewService $previewService
     * @param StoreScopeGuard $storeScopeGuard
     * @param DraftEditor $draftEditor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        EmailGatewayInterface $emailGateway,
        ContentService $contentService,
        PreviewService $previewService,
        StoreScopeGuard $storeScopeGuard,
        private readonly DraftEditor $draftEditor
    ) {
        parent::__construct($featureChecker, $emailGateway, $contentService, $previewService, $storeScopeGuard);
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Edit a %s: operations change the Hyvä CMS DRAFT (same operations as hyva_cms.draft.update, uids from '
            . 'the get tool with include_document; email components: email_section, email_heading, email_text, '
            . 'email_button, email_image, email_order_* ...); the components reach sent emails only after the publish '
            . 'tool. subject and preheader%s are NOT drafted: they change the emails customers receive IMMEDIATELY, '
            . 'so only pass them when the merchant asked for a live change.',
            static::LABEL,
            static::TEMPLATE_TYPE === EmailGatewayInterface::TYPE_NEWSLETTER ? ', sender_name and sender_email' : ''
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = $this->withTarget(Schema::object())
            ->rawProperty('operations', ['type' => 'array', 'items' => ['type' => 'object'],
                'description' => 'Draft operations: ' . implode(', ', array_keys(ContentSchema::OPERATIONS)) . '.'])
            ->string('subject', fn (StringBuilder $s) => $s->maxLength(255))
            ->string('preheader', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('Inbox preview text shown after the subject.'))
            ->string('version_name', fn (StringBuilder $s) => $s->maxLength(100))
            ->string('base_version_uid', fn (StringBuilder $s) => $s->description('Optional: draft version uid you '
                . 'read (draft_version_uid of the get tool); refused with status 409 when it changed since.'));
        if (static::TEMPLATE_TYPE === EmailGatewayInterface::TYPE_NEWSLETTER) {
            $schema->string('sender_name', fn (StringBuilder $s) => $s->maxLength(200))
                ->string('sender_email', fn (StringBuilder $s) => $s->maxLength(200));
        }
        return $schema->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $template = $this->locateTemplate($arguments);
        $result = ['id' => $template['id'], 'published' => false];
        $operations = $this->arrayArg($arguments, 'operations');
        if ($operations !== []) {
            $document = $this->contentService->getWorkingDocument(static::TEMPLATE_TYPE, $template['id']);
            $applied = $this->draftEditor->apply($document, $operations);
            $result['draft'] = $this->contentService->saveDraft(
                static::TEMPLATE_TYPE,
                $template['id'],
                $applied['document'],
                $this->stringArg($arguments, 'version_name'),
                $this->stringArg($arguments, 'base_version_uid')
            );
            $result['applied_operations'] = $applied['applied'];
        }
        $settings = array_intersect_key(
            $arguments,
            array_flip(['subject', 'preheader', 'sender_name', 'sender_email'])
        );
        if ($settings !== []) {
            $result['settings_changed'] = $this->emailGateway->updateSettings(
                static::TEMPLATE_TYPE,
                $template['id'],
                $settings
            );
            if ($template['assigned_to'] !== []) {
                $result['live_change'] = 'These settings already apply to emails sent from '
                    . count($template['assigned_to']) . ' configuration scope(s).';
            }
        }
        if ($operations === [] && $settings === []) {
            return $this->softError('Pass operations and/or settings to change.');
        }
        return $this->jsonResult($result, ['id' => $template['id']]);
    }
}
