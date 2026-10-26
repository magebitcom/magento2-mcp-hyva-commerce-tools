<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Audit;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\StoreCreditGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magento\Framework\AuthorizationInterface;

/**
 * One-line health summaries of the Hyvä Commerce 1.4 modules for hyva_commerce.audit.overview.
 *
 * Each lens is null when its module is unavailable or the caller may not read that data; the module's own audit
 * tool has the details (named in "details").
 */
class ModuleLenses
{
    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param AuthorizationInterface $authorization
     * @param ContentAcl $contentAcl
     * @param FormGatewayInterface $forms
     * @param FormRules $formRules
     * @param EmailGatewayInterface $emails
     * @param LiteralUrlAuditor $literalUrlAuditor
     * @param StoreCreditGatewayInterface $storeCredit
     * @param LinkedProductsGatewayInterface $linkedGroups
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly AuthorizationInterface $authorization,
        private readonly ContentAcl $contentAcl,
        private readonly FormGatewayInterface $forms,
        private readonly FormRules $formRules,
        private readonly EmailGatewayInterface $emails,
        private readonly LiteralUrlAuditor $literalUrlAuditor,
        private readonly StoreCreditGatewayInterface $storeCredit,
        private readonly LinkedProductsGatewayInterface $linkedGroups
    ) {
    }

    /**
     * Every lens the caller may see.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function collect(): array
    {
        return [
            'forms' => $this->forms(),
            'emails' => $this->emails(),
            'media_asset_ids' => $this->assetIds(),
            'store_credit' => $this->storeCredit(),
            'linked_products' => $this->linkedProducts(),
        ];
    }

    /**
     * Forms whose submissions go nowhere or would not be delivered.
     *
     * @return array<string, mixed>|null
     */
    private function forms(): ?array
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::FORM_BUILDER)) {
            return null;
        }
        $list = $this->forms->getList(null, null, 1, 200);
        $broken = [];
        $inactive = 0;
        foreach ($list['items'] as $form) {
            $inactive += $form['is_active'] ? 0 : 1;
            $errors = array_filter(
                $this->formRules->deliveryIssues($form, null),
                static fn (array $issue): bool => $issue['severity'] === 'error'
            );
            if ($errors !== []) {
                $broken[] = $form['identifier'];
            }
        }
        return [
            'total' => $list['total'],
            'inactive' => $inactive,
            'with_delivery_errors' => count($broken),
            'with_delivery_errors_sample' => array_slice($broken, 0, 10),
            'details' => 'hyva_form.delivery.audit',
        ];
    }

    /**
     * Transactional emails: whether Hyvä designs are used when emails are sent.
     *
     * @return array<string, mixed>|null
     */
    private function emails(): ?array
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::EMAIL_TEMPLATES)
            || !$this->authorization->isAllowed('Hyva_EmailTemplates::email_template_view')) {
            return null;
        }
        $templates = $this->emails->getList(EmailGatewayInterface::TYPE_EMAIL, null, 1, 200)['items'];
        $live = array_filter(
            $templates,
            static fn (array $t): bool => $t['hyva_enabled'] && $t['has_published'] && $t['assigned_to'] !== []
        );
        $unassigned = array_filter(
            $templates,
            static fn (array $t): bool => $t['hyva_enabled'] && $t['assigned_to'] === []
        );
        return [
            'replacement_enabled' => $this->emails->isEmailReplacementEnabled(),
            'hyva_templates' => count(array_filter($templates, static fn (array $t): bool => $t['hyva_enabled'])),
            'live_in_config' => count($live),
            'not_assigned_to_any_email' => count($unassigned),
            'details' => 'hyva_email.audit.coverage',
        ];
    }

    /**
     * Images in CMS content that are not tied to a Media Gallery asset id (break when files move).
     *
     * @return array<string, mixed>|null
     */
    private function assetIds(): ?array
    {
        $types = $this->contentAcl->allowedTypes(ContentLocator::TYPES, ContentAcl::VERB_VIEW);
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MEDIA_GALLERY) || $types === []) {
            return null;
        }
        $report = $this->literalUrlAuditor->audit($types, 200);
        return [
            'documents_checked' => $report['documents_checked'],
            'counts' => $report['counts'],
            'details' => 'hyva_media.audit.literal_urls',
        ];
    }

    /**
     * Websites where store credit is switched on.
     *
     * @return array<string, mixed>|null
     */
    private function storeCredit(): ?array
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::STORE_CREDIT)) {
            return null;
        }
        $websites = $this->storeCredit->websites();
        return [
            'websites' => count($websites),
            'enabled_on' => array_values(array_column(
                array_filter($websites, static fn (array $w): bool => $w['enabled']),
                'code'
            )),
            'details' => 'hyva_store_credit.status',
        ];
    }

    /**
     * Number of linked product groups.
     *
     * @return array<string, mixed>|null
     */
    private function linkedProducts(): ?array
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::LINKED_PRODUCTS)) {
            return null;
        }
        return [
            'groups' => $this->linkedGroups->getList(null, null, 1, 1)['total'],
            'details' => 'hyva_linked.group.list',
        ];
    }
}
