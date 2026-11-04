<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Email;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for hyva_email.* tools (Hyvä Email & Newsletter Templates 1.0, content types email_template and
 * newsletter_template, keyed by the Magento template id).
 *
 * @phpstan-import-type EmailRow from EmailGatewayInterface
 */
abstract class AbstractEmailTool extends AbstractTool
{
    public const TEMPLATE_TYPE = EmailGatewayInterface::TYPE_EMAIL;
    public const LABEL = 'email template';

    /**
     * Plural of LABEL, e.g. "email templates", for messages about the type.
     */
    public const LABEL_PLURAL = 'email templates';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::email_template_view';

    /**
     * Whether the write tool changes templates. Templates have no store view assignment (global data), so a role
     * restricted to some websites or store views may not change them.
     */
    public const CHANGES_TEMPLATES = true;

    /**
     * Resource the tool is listed with while the feature is missing or disabled: none, so tools/list hides it.
     * A feature's status tool overrides it with a resource that exists without the feature, so it stays listed.
     */
    protected const FEATURE_MISSING_ACL_RESOURCE = ContentAcl::UNAVAILABLE_RESOURCE;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param EmailGatewayInterface $emailGateway
     * @param ContentService $contentService
     * @param PreviewService $previewService
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly EmailGatewayInterface $emailGateway,
        protected readonly ContentService $contentService,
        protected readonly PreviewService $previewService,
        protected readonly StoreScopeGuard $storeScopeGuard
    ) {
    }

    /**
     * Hyvä resource the tool is listed with.
     *
     * Email Templates's resources only exist while the module is enabled; without it the tool is hidden from tools/list
     * (FEATURE_MISSING_ACL_RESOURCE) and a call answers that the feature is unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::EMAIL_TEMPLATES)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : static::FEATURE_MISSING_ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::EMAIL_TEMPLATES)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::EMAIL_TEMPLATES)
            );
        }
        if (static::IS_WRITE && static::CHANGES_TEMPLATES) {
            $this->storeScopeGuard->assertUnrestricted(static::LABEL_PLURAL);
        }
        return $this->executeEmail($arguments);
    }

    /**
     * Tool body once the module is available.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeEmail(array $arguments): ToolResultInterface;

    /**
     * Template from id (Magento template id) or code.
     *
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return EmailRow
     */
    protected function locateTemplate(array $arguments): array
    {
        $id = $this->intArg($arguments, 'id');
        if ($id === null) {
            $code = $this->stringArg($arguments, 'code');
            if ($code === null || $code === '') {
                throw new LocalizedException(__('Pass "id" or "code" to select the %1.', static::LABEL));
            }
            $matches = array_values(array_filter(
                $this->emailGateway->getList(static::TEMPLATE_TYPE, $code, 1, 200)['items'],
                static fn (array $row): bool => $row['code'] === $code
            ));
            if (count($matches) !== 1) {
                throw new LocalizedException(__(
                    $matches === [] ? '%4 "%2" does not exist.' : 'Several %3 are named "%2"; pass id.',
                    static::LABEL,
                    $code,
                    static::LABEL_PLURAL,
                    ucfirst(static::LABEL)
                ));
            }
            return $matches[0];
        }
        return $this->emailGateway->get(static::TEMPLATE_TYPE, $id)
            // Capitalised like the page and menu tools' "CMS page #3 does not exist." (T-128).
            ?? throw new LocalizedException(__('%1 #%2 does not exist.', ucfirst(static::LABEL), $id));
    }

    /**
     * Description note naming the delete tool of the type (DELETE_TOOL_NOTE)
     *
     * @return string
     */
    protected function deleteToolNote(): string
    {
        return static::TEMPLATE_TYPE === EmailGatewayInterface::TYPE_NEWSLETTER
            ? sprintf(self::DELETE_TOOL_NOTE, 'a newsletter template', 'hyva_email_newsletter_delete')
            : sprintf(self::DELETE_TOOL_NOTE, 'an email template', 'hyva_email_template_delete');
    }

    /**
     * Schema properties that select one template.
     *
     * @param ObjectBuilder $object
     * @return ObjectBuilder
     */
    protected function withTarget(ObjectBuilder $object): ObjectBuilder
    {
        return $object
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)->description('Magento template id.'))
            ->string('code', fn (StringBuilder $s) => $s->minLength(1)
                ->description('Template name (template_code). Used when id is not given.'));
    }
}
