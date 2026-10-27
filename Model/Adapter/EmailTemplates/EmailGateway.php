<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\EmailTemplates;

use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\EmailTemplates\Api\Data\EmailTemplateInterface as HyvaEmailTemplate;
use Hyva\EmailTemplates\Api\Data\NewsletterTemplateInterface as HyvaNewsletterTemplate;
use Hyva\EmailTemplates\Api\EmailTemplateRepositoryInterface;
use Hyva\EmailTemplates\Api\NewsletterTemplateRepositoryInterface;
use Hyva\EmailTemplates\Model\DummyData\VariableSubstitutor;
use Hyva\EmailTemplates\Model\NewsletterTemplateLoader;
use Hyva\EmailTemplates\Model\OrderPreviewData;
use Hyva\EmailTemplates\Model\Preview\PreviewFixtureProvider;
use Hyva\EmailTemplates\Model\Preview\PublishedPreviewContext;
use Hyva\EmailTemplates\Model\Provider\NewsletterTemplateProvider;
use Hyva\EmailTemplates\Model\Render\PublishedEmailRenderer;
use Hyva\EmailTemplates\Model\Render\PublishedNewsletterRenderer;
use Hyva\EmailTemplates\Model\Setup\EmailTemplateManager;
use Hyva\EmailTemplates\Model\TemplateLoader;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Hyva\EmailTemplates\Api\ConfigInterface as HyvaEmailConfig;
use Magento\Email\Model\ResourceModel\Template as EmailResource;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as EmailCollectionFactory;
use Magento\Email\Model\Template as EmailTemplate;
use Magento\Framework\App\Area;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Model\AbstractModel;
use Magento\Newsletter\Model\ResourceModel\Template as NewsletterResource;
use Magento\Newsletter\Model\ResourceModel\Template\CollectionFactory as NewsletterCollectionFactory;
use Magento\Newsletter\Model\Template as NewsletterTemplate;
use Magento\Newsletter\Model\TemplateFactory as NewsletterTemplateFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Hyvä Email & Newsletter Templates adapter. Hyvä's services check no permissions; the calling tools do.
 *
 * @phpstan-import-type EmailRow from EmailGatewayInterface
 */
class EmailGateway implements EmailGatewayInterface
{
    /**
     * @var array<int, list<array{path: string, scope: string, scope_id: int}>>|null
     */
    private ?array $assignments = null;

    /**
     * Hyvä rows by "type:template id"; false = known to have none.
     *
     * @var array<string, HyvaEmailTemplate|HyvaNewsletterTemplate|false>
     */
    private array $records = [];
    /**
     * @param OptionalServiceFactory $optionalServices
     * @param EmailCollectionFactory $emailCollectionFactory
     * @param NewsletterCollectionFactory $newsletterCollectionFactory
     * @param NewsletterTemplateFactory $newsletterTemplateFactory
     * @param NewsletterResource $newsletterResource
     * @param EmailResource $emailResource
     * @param TransportBuilder $transportBuilder
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly EmailCollectionFactory $emailCollectionFactory,
        private readonly NewsletterCollectionFactory $newsletterCollectionFactory,
        private readonly NewsletterTemplateFactory $newsletterTemplateFactory,
        private readonly NewsletterResource $newsletterResource,
        private readonly EmailResource $emailResource,
        private readonly TransportBuilder $transportBuilder,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getList(string $type, ?string $search, int $page, int $pageSize): array
    {
        $collection = $type === self::TYPE_NEWSLETTER
            ? $this->newsletterCollectionFactory->create()->addFieldToFilter('template_actual', '1')
            : $this->emailCollectionFactory->create();
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_') . '%';
            $collection->addFieldToFilter(
                ['template_code', 'template_subject'],
                [['like' => $like], ['like' => $like]]
            );
        }
        $collection->setOrder('template_id', 'DESC');
        $total = $collection->getSize();
        $collection->setPageSize(max(1, min(200, $pageSize)))->setCurPage(max(1, $page));
        $assigned = $type === self::TYPE_EMAIL ? $this->assignments() : [];
        $this->prefetchRecords($type, array_map('intval', array_values($collection->getColumnValues('template_id'))));
        $items = [];
        foreach ($collection as $template) {
            if ($template instanceof AbstractModel) {
                $items[] = $this->toRow($type, $template, $assigned);
            }
        }
        return ['total' => (int) $total, 'items' => $items];
    }

    /**
     * @inheritDoc
     */
    public function get(string $type, int $id): ?array
    {
        $template = $type === self::TYPE_NEWSLETTER
            ? $this->newsletterLoader()->byId($id)
            : $this->templateLoader()->byId($id);
        if (!$template instanceof AbstractModel || !$template->getId()) {
            return null;
        }
        return $this->toRow($type, $template, $type === self::TYPE_EMAIL ? $this->assignments() : []);
    }

    /**
     * @inheritDoc
     */
    public function coreDefinitions(): array
    {
        $status = $this->templateManager()->getTemplateStatus();
        $definitions = [];
        foreach (EmailTemplateManager::TEMPLATES as $definition) {
            $definitions[] = [
                'orig_code' => $definition['orig_code'],
                'name' => $definition['code'],
                'config_path' => $definition['config_path'],
                'template_id' => (int) ($status[$definition['orig_code']]['template_id'] ?? 0),
            ];
        }
        return $definitions;
    }

    /**
     * @inheritDoc
     */
    public function createFromCore(string $origCode): array
    {
        $known = array_column(EmailTemplateManager::TEMPLATES, 'orig_code');
        if (!in_array($origCode, $known, true)) {
            throw new LocalizedException(__(
                'Hyvä has no email design for "%1". Use one of: %2.',
                $origCode,
                implode(', ', $known)
            ));
        }
        $before = $this->hyvaCoreTemplateId($origCode);
        $this->templateManager()->createAll([$origCode]);
        $after = $this->hyvaCoreTemplateId($origCode);
        if ($after === 0) {
            throw new LocalizedException(__('Hyvä could not create the "%1" email template.', $origCode));
        }
        return ['template_id' => $after, 'created' => $before === 0];
    }

    /**
     * Id of Hyvä's own template for a core email, matched like EmailTemplateManager::loadByOrigCode()
     *
     * Hyvä's getTemplateStatus() matches on orig_template_code alone, so it could return a merchant's own template
     * based on the same core email.
     *
     * @param string $origCode
     * @return int 0 when Hyvä has not created it
     */
    private function hyvaCoreTemplateId(string $origCode): int
    {
        $codes = array_column(EmailTemplateManager::TEMPLATES, 'code', 'orig_code');
        $collection = $this->emailCollectionFactory->create();
        $collection->addFieldToFilter('orig_template_code', $origCode);
        $collection->addFieldToFilter('template_code', Cast::string($codes[$origCode] ?? ''));
        $collection->setPageSize(1);
        return Cast::int($collection->getFirstItem()->getData('template_id'));
    }

    /**
     * @inheritDoc
     */
    public function createNewsletter(array $settings): int
    {
        $code = trim(Cast::string($settings['code'] ?? ''));
        $senderName = trim(Cast::string($settings['sender_name'] ?? ''));
        $senderEmail = trim(Cast::string($settings['sender_email'] ?? ''));
        if ($code === '' || $senderName === '' || filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new LocalizedException(__('A newsletter needs code, sender_name and a valid sender_email.'));
        }
        $exists = $this->newsletterCollectionFactory->create()
            ->addFieldToFilter('template_actual', '1')
            ->addFieldToFilter('template_code', $code)
            ->getSize();
        if ($exists) {
            throw new LocalizedException(__('A newsletter template named "%1" already exists.', $code));
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $template = $this->newsletterTemplateFactory->create();
            $template->setTemplateCode($code);
            $template->setTemplateSubject(trim(Cast::string($settings['subject'] ?? '')));
            $template->setTemplateType(NewsletterTemplate::TYPE_HTML);
            // Same as Hyvä's admin action: stops Magento inserting its classic unsubscribe template.
            $template->setTemplateText(' ');
            $template->setTemplateSenderName($senderName);
            $template->setTemplateSenderEmail($senderEmail);
            $this->newsletterResource->save($template);
            $id = Cast::int($template->getId());
            $record = $this->newsletterProvider()->saveContent(
                $id,
                $this->newsletterProvider()->getBlankContentJson($id),
                false
            );
            if (!$record instanceof HyvaNewsletterTemplate) {
                throw new LocalizedException(__('Hyvä could not create the newsletter content.'));
            }
            $record->setIsLiveviewEnabled(true);
            $preheader = trim(Cast::string($settings['preheader'] ?? ''));
            $record->setPreheader($preheader !== '' ? $preheader : null);
            $this->newsletterRepository()->save($record);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e instanceof LocalizedException
                ? $e
                : new LocalizedException(__($e->getMessage()), $e instanceof \Exception ? $e : null);
        }
        return $id;
    }

    /**
     * @inheritDoc
     */
    public function updateSettings(string $type, int $id, array $settings): array
    {
        $changed = [];
        $template = $type === self::TYPE_NEWSLETTER
            ? $this->newsletterLoader()->byId($id)
            : $this->templateLoader()->byId($id);
        if (!$template instanceof AbstractModel || !$template->getId()) {
            throw new LocalizedException(__('Template #%1 does not exist.', $id));
        }
        $templateFields = ['subject' => 'template_subject'];
        if ($type === self::TYPE_NEWSLETTER) {
            $templateFields += ['sender_name' => 'template_sender_name', 'sender_email' => 'template_sender_email'];
        }
        foreach ($templateFields as $key => $column) {
            if (array_key_exists($key, $settings) && is_scalar($settings[$key])) {
                $template->setData($column, trim((string) $settings[$key]));
                $changed[] = $key;
            }
        }
        if ($changed !== []) {
            $type === self::TYPE_NEWSLETTER
                ? $this->newsletterResource->save($template)
                : $this->emailResource->save($template);
        }
        if (array_key_exists('preheader', $settings)) {
            $record = $this->hyvaRecord($type, $id);
            if ($record === null) {
                throw new LocalizedException(__('Template #%1 has no Hyvä content yet; save a draft first.', $id));
            }
            $preheader = trim(Cast::string($settings['preheader']));
            $record->setPreheader($preheader !== '' ? $preheader : null);
            $record instanceof HyvaNewsletterTemplate
                ? $this->newsletterRepository()->save($record)
                : $this->emailRepository()->save($record);
            $changed[] = 'preheader';
        }
        return $changed;
    }

    /**
     * @inheritDoc
     */
    public function render(string $type, int $id, string $edition, ?int $storeId): string
    {
        $record = $this->hyvaRecord($type, $id);
        $content = $record === null ? '' : (string) ($edition === 'draft'
            ? $record->getDraftContent()
            : $record->getPublishedContent());
        if ($record === null || trim($content) === '') {
            throw new LocalizedException(__('Template #%1 has no %2 Hyvä content to render.', $id, $edition));
        }
        // The renderer reads published content only: render a copy carrying the requested edition.
        $copy = clone $record;
        $copy->setPublishedContent($content);
        $store = $storeId ?? (int) $this->storeManager->getDefaultStoreView()?->getId();

        if ($type === self::TYPE_NEWSLETTER) {
            $template = $this->newsletterLoader()->byId($id);
            if (!$template instanceof NewsletterTemplate || !$copy instanceof HyvaNewsletterTemplate) {
                throw new LocalizedException(__('Newsletter #%1 does not exist.', $id));
            }
            $template->setDesignConfig(['area' => Area::AREA_FRONTEND, 'store' => $store]);
            return $this->previewContext()->run(fn (): string => $this->variableSubstitutor()->substitute(
                $this->newsletterRenderer()->render($copy, $template),
                $store
            ));
        }
        $template = $this->templateLoader()->byId($id);
        if (!$template instanceof EmailTemplate || !$copy instanceof HyvaEmailTemplate) {
            throw new LocalizedException(__('Email template #%1 does not exist.', $id));
        }
        $template->setDesignConfig(['area' => Area::AREA_FRONTEND, 'store' => $store]);
        return $this->previewContext()->run(fn (): string => $this->emailRenderer()->render($copy, $template));
    }

    /**
     * @inheritDoc
     */
    public function sendTest(int $id, string $recipient, ?int $storeId): void
    {
        $record = $this->hyvaRecord(self::TYPE_EMAIL, $id);
        if ($record === null || !$record->isLiveviewEnabled() || trim((string) $record->getPublishedContent()) === '') {
            throw new LocalizedException(__('Email template #%1 has no published Hyvä content to send.', $id));
        }
        // Same sample variables as Hyvä's admin "Send test" (Controller\Adminhtml\Test\Send).
        $store = $storeId !== null && $storeId > 0 ? $storeId : 0;
        $customer = $this->fixtureProvider()->getCustomerData($store > 0 ? (string) $store : null);
        $order = $this->orderPreviewData()->getOrderContext($store) ?? [];
        $first = Cast::string($customer['first_name'] ?? '');
        $last = Cast::string($customer['last_name'] ?? '');
        $name = trim($first . ' ' . $last);
        $address = implode(', ', array_filter([
            $name,
            Cast::string($customer['street'] ?? ''),
            Cast::string($customer['city'] ?? ''),
            Cast::string($customer['country'] ?? ''),
        ]));
        $this->transportBuilder
            ->setTemplateIdentifier((string) $id)
            ->setTemplateOptions(
                ['area' => Area::AREA_FRONTEND, 'store' => $store > 0 ? $store : Store::DEFAULT_STORE_ID]
            )
            ->setTemplateVars([
                'customer' => new DataObject([
                    'firstname' => $first, 'first_name' => $first, 'lastname' => $last, 'last_name' => $last,
                    'name' => $name, 'email' => Cast::string($customer['email'] ?? ''),
                ]),
                'order' => new DataObject([
                    'increment_id' => $order['increment_id'] ?? '000000001',
                    'grand_total' => $order['grand_total'] ?? '$99.00',
                    'status' => 'processing',
                    'shipping_description' => $order['shipping_description'] ?? 'Flat Rate - Fixed',
                ]),
                'order_data' => new DataObject([
                    'customer_name' => $name,
                    'email_customer_note' => Cast::string($customer['customer_note'] ?? ''),
                    'is_not_virtual' => $order['is_not_virtual'] ?? '1',
                ]),
                'formattedBillingAddress' => $address,
                'formattedShippingAddress' => $address,
                'payment_html' => $order['payment_html'] ?? '<p>Credit Card</p>',
                'created_at_formatted' => $order['created_at_formatted'] ?? date('F j, Y'),
            ])
            ->setFrom('general')
            ->addTo($recipient)
            ->getTransport()
            ->sendMessage();
    }

    /**
     * @inheritDoc
     */
    public function isEmailReplacementEnabled(): bool
    {
        return $this->hyvaEmailConfig()->isEmailTemplateEnabled();
    }

    /**
     * @inheritDoc
     */
    public function configUsage(): array
    {
        $paths = array_column(EmailTemplateManager::TEMPLATES, 'config_path');
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll($connection->select()
            ->from($this->resourceConnection->getTableName('core_config_data'), ['path', 'scope', 'scope_id', 'value'])
            ->where('path IN (?)', $paths));
        $byPath = [];
        foreach ($rows as $row) {
            $value = Cast::string($row['value'] ?? '');
            $byPath[Cast::string($row['path'])][] = [
                'scope' => Cast::string($row['scope']),
                'scope_id' => Cast::int($row['scope_id']),
                'value' => $value,
                'template_id' => ctype_digit($value) ? (int) $value : 0,
            ];
        }
        $usage = [];
        foreach (EmailTemplateManager::TEMPLATES as $definition) {
            $usage[] = [
                'orig_code' => $definition['orig_code'],
                'name' => $definition['code'],
                'config_path' => $definition['config_path'],
                'scopes' => $byPath[$definition['config_path']] ?? [],
            ];
        }
        return $usage;
    }

    /**
     * Hyvä content row of a template.
     *
     * @param string $type
     * @param int $id
     * @param bool $cached Allow the per-request copy (list rows only)
     * @return HyvaEmailTemplate|HyvaNewsletterTemplate|null
     */
    private function hyvaRecord(
        string $type,
        int $id,
        bool $cached = false
    ): HyvaEmailTemplate|HyvaNewsletterTemplate|null {
        $key = $type . ':' . $id;
        // Writes and renders always read the row fresh: a cached copy saved back would revert a draft saved since.
        if (!$cached || !array_key_exists($key, $this->records)) {
            $record = $type === self::TYPE_NEWSLETTER
                ? $this->newsletterRepository()->getByNewsletterTemplateId($id)
                : $this->emailRepository()->getByEmailTemplateId($id);
            $this->records[$key] = $record ?? false;
        }
        $record = $this->records[$key];
        return $record === false ? null : clone $record;
    }

    /**
     * Load the Hyvä rows of a page of templates with one query.
     *
     * @param string $type
     * @param array $ids
     * @return void
     * @phpstan-param list<int> $ids
     */
    private function prefetchRecords(string $type, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $field = $type === self::TYPE_NEWSLETTER ? 'newsletter_template_id' : 'email_template_id';
        $criteria = $this->searchCriteriaBuilder->addFilter($field, $ids, 'in')->create();
        $result = $type === self::TYPE_NEWSLETTER
            ? $this->newsletterRepository()->getList($criteria)
            : $this->emailRepository()->getList($criteria);
        foreach ($ids as $id) {
            $this->records[$type . ':' . $id] = false;
        }
        foreach ($result->getItems() as $record) {
            $templateId = $record instanceof HyvaNewsletterTemplate
                ? $record->getNewsletterTemplateId()
                : $record->getEmailTemplateId();
            $this->records[$type . ':' . (int) $templateId] = $record;
        }
    }

    /**
     * Template id → config paths/scopes that send it.
     *
     * @return array
     * @phpstan-return array<int, list<array{path: string, scope: string, scope_id: int}>>
     */
    private function assignments(): array
    {
        if ($this->assignments !== null) {
            return $this->assignments;
        }
        $assigned = [];
        foreach ($this->configUsage() as $usage) {
            foreach ($usage['scopes'] as $scope) {
                if ($scope['template_id'] > 0) {
                    $assigned[$scope['template_id']][] = [
                        'path' => $usage['config_path'],
                        'scope' => $scope['scope'],
                        'scope_id' => $scope['scope_id'],
                    ];
                }
            }
        }
        $this->assignments = $assigned;
        return $assigned;
    }

    /**
     * Row.
     *
     * @param string $type
     * @param AbstractModel $template
     * @param array $assigned
     * @return array
     * @phpstan-param array<int, list<array{path: string, scope: string, scope_id: int}>> $assigned
     * @phpstan-return EmailRow
     */
    private function toRow(string $type, AbstractModel $template, array $assigned): array
    {
        $id = Cast::int($template->getId());
        $record = $this->hyvaRecord($type, $id, true);
        return [
            'id' => $id,
            'type' => $type,
            'code' => Cast::string($template->getData('template_code')),
            'subject' => Cast::string($template->getData('template_subject')),
            'orig_template_code' => Cast::string($template->getData('orig_template_code')),
            'sender_name' => Cast::string($template->getData('template_sender_name')),
            'sender_email' => Cast::string($template->getData('template_sender_email')),
            'hyva_enabled' => (bool) $record?->isLiveviewEnabled(),
            'has_draft' => trim((string) $record?->getDraftContent()) !== '',
            'has_published' => trim((string) $record?->getPublishedContent()) !== '',
            'preheader' => (string) $record?->getPreheader(),
            'assigned_to' => $assigned[$id] ?? [],
        ];
    }

    /**
     * EmailTemplateRepositoryInterface from the optional package, created on first use.
     *
     * @return EmailTemplateRepositoryInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function emailRepository(): EmailTemplateRepositoryInterface
    {
        return $this->optionalServices->get(EmailTemplateRepositoryInterface::class);
    }

    /**
     * NewsletterTemplateRepositoryInterface from the optional package, created on first use.
     *
     * @return NewsletterTemplateRepositoryInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function newsletterRepository(): NewsletterTemplateRepositoryInterface
    {
        return $this->optionalServices->get(NewsletterTemplateRepositoryInterface::class);
    }

    /**
     * NewsletterTemplateProvider from the optional package, created on first use.
     *
     * @return NewsletterTemplateProvider
     * @throws LocalizedException When the package is not installed.
     */
    private function newsletterProvider(): NewsletterTemplateProvider
    {
        return $this->optionalServices->get(NewsletterTemplateProvider::class);
    }

    /**
     * EmailTemplateManager from the optional package, created on first use.
     *
     * @return EmailTemplateManager
     * @throws LocalizedException When the package is not installed.
     */
    private function templateManager(): EmailTemplateManager
    {
        return $this->optionalServices->get(EmailTemplateManager::class);
    }

    /**
     * TemplateLoader from the optional package, created on first use.
     *
     * @return TemplateLoader
     * @throws LocalizedException When the package is not installed.
     */
    private function templateLoader(): TemplateLoader
    {
        return $this->optionalServices->get(TemplateLoader::class);
    }

    /**
     * NewsletterTemplateLoader from the optional package, created on first use.
     *
     * @return NewsletterTemplateLoader
     * @throws LocalizedException When the package is not installed.
     */
    private function newsletterLoader(): NewsletterTemplateLoader
    {
        return $this->optionalServices->get(NewsletterTemplateLoader::class);
    }

    /**
     * PublishedEmailRenderer from the optional package, created on first use.
     *
     * @return PublishedEmailRenderer
     * @throws LocalizedException When the package is not installed.
     */
    private function emailRenderer(): PublishedEmailRenderer
    {
        return $this->optionalServices->get(PublishedEmailRenderer::class);
    }

    /**
     * PublishedNewsletterRenderer from the optional package, created on first use.
     *
     * @return PublishedNewsletterRenderer
     * @throws LocalizedException When the package is not installed.
     */
    private function newsletterRenderer(): PublishedNewsletterRenderer
    {
        return $this->optionalServices->get(PublishedNewsletterRenderer::class);
    }

    /**
     * PublishedPreviewContext from the optional package, created on first use.
     *
     * @return PublishedPreviewContext
     * @throws LocalizedException When the package is not installed.
     */
    private function previewContext(): PublishedPreviewContext
    {
        return $this->optionalServices->get(PublishedPreviewContext::class);
    }

    /**
     * VariableSubstitutor from the optional package, created on first use.
     *
     * @return VariableSubstitutor
     * @throws LocalizedException When the package is not installed.
     */
    private function variableSubstitutor(): VariableSubstitutor
    {
        return $this->optionalServices->get(VariableSubstitutor::class);
    }

    /**
     * PreviewFixtureProvider from the optional package, created on first use.
     *
     * @return PreviewFixtureProvider
     * @throws LocalizedException When the package is not installed.
     */
    private function fixtureProvider(): PreviewFixtureProvider
    {
        return $this->optionalServices->get(PreviewFixtureProvider::class);
    }

    /**
     * OrderPreviewData from the optional package, created on first use.
     *
     * @return OrderPreviewData
     * @throws LocalizedException When the package is not installed.
     */
    private function orderPreviewData(): OrderPreviewData
    {
        return $this->optionalServices->get(OrderPreviewData::class);
    }

    /**
     * HyvaEmailConfig from the optional package, created on first use.
     *
     * @return HyvaEmailConfig
     * @throws LocalizedException When the package is not installed.
     */
    private function hyvaEmailConfig(): HyvaEmailConfig
    {
        return $this->optionalServices->get(HyvaEmailConfig::class);
    }
}
