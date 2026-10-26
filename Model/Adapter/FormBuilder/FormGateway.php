<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\FormBuilder;

use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\FormBuilder\Api\Data\FormInterface;
use Hyva\FormBuilder\Api\Data\FormInterfaceFactory;
use Hyva\FormBuilder\Api\FormRepositoryInterface;
use Hyva\FormBuilder\Model\Form\IdentifierValidator;
use Hyva\FormBuilder\Model\Form\UniqueIdentifier;
use Hyva\FormBuilder\Model\ResourceModel\Form as FormResource;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;

/**
 * Hyvä Form Builder adapter. Hyvä's services do not check permissions; the calling tools do.
 *
 * @phpstan-import-type FormRow from FormGatewayInterface
 */
class FormGateway implements FormGatewayInterface
{
    /**
     * @param OptionalServiceFactory $optionalServices
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getList(?string $search, ?int $storeId, int $page, int $pageSize): array
    {
        $builder = $this->searchCriteriaBuilder;
        if ($search !== null && $search !== '') {
            $builder->addFilter('title', '%' . addcslashes($search, '%_') . '%', 'like');
        }
        if ($storeId !== null) {
            $builder->addFilter('store_id', $storeId);
        }
        $builder->addSortOrder($this->sortOrderBuilder->setField('id')->setDescendingDirection()->create());
        $criteria = $builder->setCurrentPage(max(1, $page))->setPageSize(max(1, min(200, $pageSize)))->create();
        $result = $this->formRepository()->getList($criteria);
        $items = [];
        foreach ($result->getItems() as $form) {
            $items[] = $this->toRow($form);
        }
        return ['total' => (int) $result->getTotalCount(), 'items' => $items];
    }

    /**
     * @inheritDoc
     */
    public function get(int $id): ?array
    {
        try {
            return $this->toRow($this->formRepository()->get($id));
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function findByIdentifier(string $identifier, ?int $storeId = null): array
    {
        $builder = $this->searchCriteriaBuilder->addFilter('identifier', $identifier);
        if ($storeId !== null) {
            $builder->addFilter('store_id', $storeId);
        }
        $rows = [];
        foreach ($this->formRepository()->getList($builder->create())->getItems() as $form) {
            $rows[] = $this->toRow($form);
        }
        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function create(array $settings): int
    {
        $form = $this->formFactory()->create();
        $settings += ['store_ids' => [0], 'is_active' => false];
        $this->apply($form, $settings);
        return (int) $this->formRepository()->save($form)->getId();
    }

    /**
     * @inheritDoc
     */
    public function update(int $id, array $settings): array
    {
        try {
            $form = $this->formRepository()->get($id);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Form #%1 does not exist.', $id));
        }
        $changed = $this->apply($form, $settings);
        if ($changed !== []) {
            $this->formRepository()->save($form);
        }
        return $changed;
    }

    /**
     * @inheritDoc
     */
    public function identifierError(string $identifier, array $storeIds, ?int $excludeId = null): ?string
    {
        try {
            $this->identifierValidator()->validate($identifier);
        } catch (LocalizedException $e) {
            return $e->getMessage();
        }
        $collision = $this->formResource()->findCollidingFormId($identifier, $storeIds ?: [0], $excludeId);
        return $collision !== null
            ? sprintf('Form #%d already uses identifier "%s" on these store views.', $collision, $identifier)
            : null;
    }

    /**
     * @inheritDoc
     */
    public function suggestIdentifier(string $base, array $storeIds): string
    {
        return $this->uniqueIdentifier()->next($base, $storeIds ?: [0]);
    }

    /**
     * Apply settings; returns the names that were set.
     *
     * @param FormInterface $form
     * @param array $settings
     * @return array
     * @phpstan-param array<string, mixed> $settings
     * @phpstan-return list<string>
     */
    private function apply(FormInterface $form, array $settings): array
    {
        $changed = [];
        foreach ($settings as $name => $value) {
            if (!in_array($name, self::SETTINGS, true) || $value === null) {
                continue;
            }
            match ($name) {
                'title' => $form->setTitle(Cast::string($value)),
                'identifier' => $form->setIdentifier(Cast::string($value)),
                'store_ids' => $form->setStores(Cast::intList(is_array($value) ? $value : [$value]) ?: [0]),
                'is_active' => $form->setIsActive(Cast::bool($value)),
                'recipient_to' => $form->setRecipientTo($this->addresses($value)),
                'recipient_cc' => $form->setRecipientCc($this->addresses($value)),
                'recipient_bcc' => $form->setRecipientBcc($this->addresses($value)),
                'subject_template' => $form->setSubjectTemplate(Cast::string($value)),
                'webhook_url' => $form->setWebhookUrl(Cast::string($value)),
                'webhook_format' => $form->setWebhookFormat(Cast::string($value)),
                'is_captcha_enabled' => $form->setIsCaptchaEnabled(Cast::bool($value)),
                'preview_url_key' => $form->setPreviewUrlKey(Cast::string($value) ?: null),
            };
            $changed[] = (string) $name;
        }
        return $changed;
    }

    /**
     * Comma-separated addresses from a string or list.
     *
     * @param mixed $value
     * @return string
     */
    private function addresses(mixed $value): string
    {
        $list = is_array($value) ? $value : preg_split('/[,;]/', Cast::string($value));
        return implode(',', array_values(array_filter(array_map(
            static fn ($address): string => trim(Cast::string($address)),
            is_array($list) ? $list : []
        ))));
    }

    /**
     * Row.
     *
     * @param FormInterface $form
     * @return array
     * @phpstan-return FormRow
     */
    private function toRow(FormInterface $form): array
    {
        $id = (int) $form->getId();
        $stores = $form->getStores() ?? [];
        return [
            'id' => $id,
            'identifier' => (string) $form->getIdentifier(),
            'title' => (string) $form->getTitle(),
            'is_active' => $form->isActive(),
            'store_ids' => $stores !== []
                ? Cast::intList($stores)
                : Cast::intList($this->formResource()->lookupStoreIds($id)),
            'recipient_to' => (string) $form->getRecipientTo(),
            'recipient_cc' => (string) $form->getRecipientCc(),
            'recipient_bcc' => (string) $form->getRecipientBcc(),
            'subject_template' => (string) $form->getSubjectTemplate(),
            'webhook_url' => (string) $form->getWebhookUrl(),
            'webhook_format' => (string) $form->getWebhookFormat(),
            'is_captcha_enabled' => $form->isCaptchaEnabled(),
            'preview_url_key' => (string) $form->getPreviewUrlKey(),
            'has_draft' => trim((string) $form->getDraftContent()) !== '',
            'has_published' => !in_array(trim((string) $form->getPublishedContent()), ['', '[]'], true),
            'updated_at' => $form instanceof AbstractModel ? Cast::string($form->getData('update_time')) : '',
        ];
    }

    /**
     * FormRepositoryInterface from the optional package, created on first use.
     *
     * @return FormRepositoryInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function formRepository(): FormRepositoryInterface
    {
        return $this->optionalServices->get(FormRepositoryInterface::class);
    }

    /**
     * FormInterfaceFactory from the optional package, created on first use.
     *
     * @return FormInterfaceFactory
     * @throws LocalizedException When the package is not installed.
     */
    private function formFactory(): FormInterfaceFactory
    {
        return $this->optionalServices->get(FormInterfaceFactory::class);
    }

    /**
     * FormResource from the optional package, created on first use.
     *
     * @return FormResource
     * @throws LocalizedException When the package is not installed.
     */
    private function formResource(): FormResource
    {
        return $this->optionalServices->get(FormResource::class);
    }

    /**
     * IdentifierValidator from the optional package, created on first use.
     *
     * @return IdentifierValidator
     * @throws LocalizedException When the package is not installed.
     */
    private function identifierValidator(): IdentifierValidator
    {
        return $this->optionalServices->get(IdentifierValidator::class);
    }

    /**
     * UniqueIdentifier from the optional package, created on first use.
     *
     * @return UniqueIdentifier
     * @throws LocalizedException When the package is not installed.
     */
    private function uniqueIdentifier(): UniqueIdentifier
    {
        return $this->optionalServices->get(UniqueIdentifier::class);
    }
}
