<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractEmailTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;

/**
 * `hyva_email.newsletter.create_draft`.
 */
class NewsletterCreateDraft extends AbstractEmailTool
{
    public const TOOL_NAME = 'hyva_email.newsletter.create_draft';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_email_newsletter_create_draft';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::newsletter_template_save';
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_NEWSLETTER;
    public const LABEL = 'newsletter template';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param EmailGatewayInterface $emailGateway
     * @param ContentService $contentService
     * @param PreviewService $previewService
     * @param StoreScopeGuard $storeScopeGuard
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        EmailGatewayInterface $emailGateway,
        ContentService $contentService,
        PreviewService $previewService,
        StoreScopeGuard $storeScopeGuard,
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor
    ) {
        parent::__construct($featureChecker, $emailGateway, $contentService, $previewService, $storeScopeGuard);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Create Hyvä Newsletter Template';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a newsletter template designed in Hyvä CMS, as a DRAFT: name, subject, sender, preheader '
            . 'and optional email components added inside the email_wrapper root (email_section, email_heading, '
            . 'email_text, email_button, email_image ...). Nothing is sent; queue newsletters from it in the admin '
            . 'after publishing.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = Schema::object()
            ->string('code', fn (StringBuilder $s) => $s->minLength(1)->maxLength(150)
                ->description('Template name, unique.'))
            ->string('subject', fn (StringBuilder $s) => $s->maxLength(255))
            ->string('sender_name', fn (StringBuilder $s) => $s->minLength(1)->maxLength(200))
            ->string('sender_email', fn (StringBuilder $s) => $s->minLength(3)->maxLength(200))
            ->string('preheader', fn (StringBuilder $s) => $s->maxLength(255))
            ->rawProperty('components', ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Email '
                . 'components {component, fields, children} placed in the email_wrapper root.'])
            ->toArray();
        $schema['required'] = ['code', 'sender_name', 'sender_email'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $components = $this->draftEditor->buildNodes(array_values($this->arrayArg($arguments, 'components')));
        if ($components !== []) {
            // Validate before anything is created: a failed save afterwards would leave an empty newsletter
            // whose name blocks a retry.
            $probe = $this->componentTree->insertNode(
                $this->componentTree->newDocument(self::TEMPLATE_TYPE, 0),
                $this->componentTree->createNode('email_wrapper', [], $components)
            );
            $validation = $this->contentService->validate($probe);
            if (!$validation['valid']) {
                return $this->softError(
                    'Nothing was created: the components are invalid.',
                    ['validation' => $validation]
                );
            }
        }
        $id = $this->emailGateway->createNewsletter($arguments);
        $saved = null;
        if ($components !== []) {
            $document = $this->contentService->getWorkingDocument(self::TEMPLATE_TYPE, $id);
            $rootUid = Cast::string($this->componentTree->getRootNodes($document)[0]['uid'] ?? '');
            foreach ($components as $node) {
                $document = $this->componentTree->insertNode($document, $node, $rootUid !== '' ? $rootUid : null);
            }
            $saved = $this->contentService->saveDraft(self::TEMPLATE_TYPE, $id, $document, 'Created by MCP');
        }
        return $this->jsonResult([
            'created' => true,
            'id' => $id,
            'draft' => $saved,
            'published' => false,
            'template' => $this->emailGateway->get(self::TEMPLATE_TYPE, $id),
            'preview' => $this->previewService->getPreview(self::TEMPLATE_TYPE, $id),
        ], ['id' => $id]);
    }
}
