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
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_EmailTemplates::email_template_view';

    /**
     * Whether the write tool changes templates. Templates have no store view assignment (global data), so a role
     * restricted to some websites or store views may not change them.
     */
    public const CHANGES_TEMPLATES = true;

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
     * The module's resources only exist while it is enabled; without it the tool stays listed under Hyvä CMS
     * access and answers that the feature is unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::EMAIL_TEMPLATES)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Hyva_Cms::cms';
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
            $this->storeScopeGuard->assertUnrestricted(ucfirst(static::LABEL) . 's');
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
                    $matches === [] ? '%1 "%2" does not exist.' : 'Several %1s are named "%2"; pass id.',
                    static::LABEL,
                    $code
                ));
            }
            return $matches[0];
        }
        return $this->emailGateway->get(static::TEMPLATE_TYPE, $id)
            ?? throw new LocalizedException(__('%1 #%2 does not exist.', static::LABEL, $id));
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
