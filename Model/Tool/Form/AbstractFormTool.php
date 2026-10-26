<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Form;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for hyva_form.* tools (Hyvä Form Builder 1.0, content type "form").
 *
 * Hyvä's form services check no permissions; these tools are listed with Hyva_FormBuilder::form_* resources and
 * Hyvä's own provider runs the publish rules (UnprocessableContentException → status 422).
 *
 * @phpstan-import-type FormRow from FormGatewayInterface
 */
abstract class AbstractFormTool extends AbstractTool
{
    public const CONTENT_TYPE = 'form';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param FormGatewayInterface $formGateway
     * @param ContentService $contentService
     * @param FormRules $formRules
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly FormGatewayInterface $formGateway,
        protected readonly ContentService $contentService,
        protected readonly FormRules $formRules,
        protected readonly StoreScopeGuard $storeScopeGuard
    ) {
    }

    /**
     * Hyvä resource the tool is listed with.
     *
     * Form Builder's resources only exist while the module is enabled; without it the tool stays listed under
     * Hyvä CMS access and answers that the feature is unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::FORM_BUILDER)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Hyva_Cms::cms';
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::FORM_BUILDER)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::FORM_BUILDER)
            );
        }
        return $this->executeForm($arguments);
    }

    /**
     * Tool body once Form Builder is available.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeForm(array $arguments): ToolResultInterface;

    /**
     * Form the caller may see (website/store view restriction applied).
     *
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @throws AuthorizationException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return FormRow
     */
    protected function locateForm(array $arguments): array
    {
        $form = $this->findForm($arguments);
        $this->storeScopeGuard->assertReadable('form #' . $form['id'], $form['store_ids']);
        return $form;
    }

    /**
     * Form the caller may change: every store view it is assigned to is within the caller's restriction.
     *
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @throws AuthorizationException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return FormRow
     */
    protected function locateFormForWrite(array $arguments): array
    {
        $form = $this->findForm($arguments);
        $this->storeScopeGuard->assertWritable('form #' . $form['id'], $form['store_ids']);
        return $form;
    }

    /**
     * Form from id, or identifier (+ store_id when several store views use it), scope not checked.
     *
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return FormRow
     */
    private function findForm(array $arguments): array
    {
        $id = $this->intArg($arguments, 'id');
        if ($id !== null) {
            return $this->formGateway->get($id)
                ?? throw new LocalizedException(__('Form #%1 does not exist.', $id));
        }
        $identifier = $this->stringArg($arguments, 'identifier');
        if ($identifier === null || $identifier === '') {
            throw new LocalizedException(__('Pass either "id" or "identifier" to select the form.'));
        }
        $matches = $this->formGateway->findByIdentifier($identifier, $this->intArg($arguments, 'store_id'));
        if ($matches === []) {
            throw new LocalizedException(__('Form "%1" does not exist.', $identifier));
        }
        if (count($matches) > 1) {
            throw new LocalizedException(__(
                'Several forms use identifier "%1" (ids %2). Pass id or store_id.',
                $identifier,
                implode(', ', array_column($matches, 'id'))
            ));
        }
        return $matches[0];
    }

    /**
     * Schema properties that select one form: id, or identifier (+ store_id).
     *
     * @param ObjectBuilder $object
     * @return ObjectBuilder
     */
    protected function withFormTarget(ObjectBuilder $object): ObjectBuilder
    {
        return $object
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)->description('Form id.'))
            ->string('identifier', fn (StringBuilder $s) => $s->minLength(1)
                ->description('Form identifier (a-z, 0-9, _). Used when id is not given.'))
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0)
                ->description('Store view, when several forms share the identifier on different store views.'));
    }

    /**
     * Schema properties for form settings (entity fields that apply immediately).
     *
     * @param ObjectBuilder $object
     * @return ObjectBuilder
     */
    protected function withSettings(ObjectBuilder $object): ObjectBuilder
    {
        return $object
            ->string('title', fn (StringBuilder $s) => $s->minLength(1)->maxLength(255))
            ->rawProperty('store_ids', ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0],
                'description' => 'Store views; [0] = all (default).'])
            ->rawProperty('recipient_to', ['type' => ['string', 'array'], 'description' => 'Who receives '
                . 'submissions: comma-separated addresses or a list. Without it (and without webhook_url) Hyvä '
                . 'sends nothing.'])
            ->rawProperty('recipient_cc', ['type' => ['string', 'array']])
            ->rawProperty('recipient_bcc', ['type' => ['string', 'array']])
            ->string('subject_template', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('Email subject; may use {{field_name}} placeholders.'))
            ->string('webhook_url', fn (StringBuilder $s) => $s->description('Optional http(s) URL that also '
                . 'receives each submission. Local and private addresses are refused by Hyvä.'))
            ->string('webhook_format', fn (StringBuilder $s) => $s->enum(FormGatewayInterface::WEBHOOK_FORMATS))
            ->boolean('is_captcha_enabled', fn (BooleanBuilder $b) => $b->description('Default: on.'))
            ->string('preview_url_key', fn (StringBuilder $s) => $s->maxLength(100)
                ->description('Storefront path of a page that embeds the form (a-z, 0-9, -, _, /). Hyvä previews '
                    . 'the form on it and reports it as published_url; it is not a page of its own.'));
    }

    /**
     * Settings from the arguments (only the keys that were passed).
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<string, mixed>
     */
    protected function settingsFrom(array $arguments): array
    {
        return array_intersect_key($arguments, array_flip(FormGatewayInterface::SETTINGS));
    }
}
