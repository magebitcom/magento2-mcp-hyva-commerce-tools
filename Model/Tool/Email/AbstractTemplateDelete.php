<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Email;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\InUseDeleteGate;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Exception\AclRefusalException;

/**
 * hyva_email.template.delete / hyva_email.newsletter.delete: confirm-gated delete of one email or newsletter template
 * (T-107, the T-22 delete pattern).
 *
 * A template still in use is deleted only with "force": true next to "confirm": true (InUseDeleteGate). Permissions:
 * the tool's own resource (granted to no role by default), the Magento admin permission the native delete action
 * needs (ADMIN_DELETE_RESOURCE) and, where Hyvä has one, its delete verb (HYVA_DELETE_RESOURCE). The core checks the
 * tool resource and the listing resource (the first of the others the role lacks); executeEmail() checks them again.
 * Templates apply to every store view, so a role restricted to some websites gets the -32004 scope refusal of every
 * email write.
 */
abstract class AbstractTemplateDelete extends AbstractEmailTool
{
    use InUseDeleteGate;

    public const IS_WRITE = true;

    /**
     * Permission of the Magento admin delete action (its ADMIN_RESOURCE).
     */
    public const ADMIN_DELETE_RESOURCE = '';

    /**
     * Hyvä's delete verb for the type, '' when Hyvä has none.
     */
    public const HYVA_DELETE_RESOURCE = '';

    /**
     * What deleting a template in use does to its usages.
     */
    public const IN_USE_CONSEQUENCE = '';

    /**
     * What the result of a forced delete tells the caller after the usage list.
     */
    public const FORCED_AFTERMATH = 'Those references are now broken: remove or replace them.';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param EmailGatewayInterface $emailGateway
     * @param ContentService $contentService
     * @param PreviewService $previewService
     * @param StoreScopeGuard $storeScopeGuard
     * @param CallerContext $callerContext
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        EmailGatewayInterface $emailGateway,
        ContentService $contentService,
        PreviewService $previewService,
        StoreScopeGuard $storeScopeGuard,
        private readonly CallerContext $callerContext
    ) {
        parent::__construct($featureChecker, $emailGateway, $contentService, $previewService, $storeScopeGuard);
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withTarget(Schema::object())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Delete, after reviewing the preview. Without it nothing is deleted.'
            ))
            ->boolean('force', fn (BooleanBuilder $b) => $b->description(
                'Also delete when the preview says it is still in use (in_use: true); needs "confirm": true as '
                    . 'well. Default false: a template in use is refused. Not needed when it is not in use.'
            ))
            ->toArray();
    }

    /**
     * Listed with the first delete permission the role lacks, else the last one (Hyvä's verb where there is one)
     *
     * The core checks this resource on tools/list and tools/call, so a role without one of them neither sees nor
     * runs the tool. Without the module the tool is hidden; without an MCP caller (CLI) the admin permission is used.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        if (!$this->featureChecker->getStatus(FeatureCheckerInterface::EMAIL_TEMPLATES)['enabled']) {
            return static::FEATURE_MISSING_ACL_RESOURCE;
        }
        $resources = $this->deleteResources();
        foreach ($resources as $resource) {
            $allowed = $this->callerContext->isAllowed($resource);
            if ($allowed === null) {
                return static::ADMIN_DELETE_RESOURCE;
            }
            if (!$allowed) {
                return $resource;
            }
        }
        return $resources[count($resources) - 1];
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $this->assertDeletePermissions();
        $template = $this->locateTemplate($arguments);
        $usage = $this->emailGateway->deletionUsage(static::TEMPLATE_TYPE, $template['id']);
        $inUse = $usage['used'];
        $summary = [
            'entity_type' => static::TEMPLATE_TYPE,
            'id' => $template['id'],
            'code' => $template['code'],
            'subject' => $template['subject'],
            'hyva_enabled' => $template['hyva_enabled'],
            'has_published' => $template['has_published'],
            'in_use' => $inUse,
        ];
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(
                'delete_' . static::TEMPLATE_TYPE,
                [$summary],
                ['in_use' => $inUse, 'force_required' => $inUse, 'usage' => $usage],
                null,
                sprintf(
                    'Deleting removes the %s with its Hyvä drafts and version history; it cannot be undone.',
                    static::LABEL
                ) . $this->historyNote($usage) . ($inUse ? $this->inUseHint(static::IN_USE_CONSEQUENCE) : '')
            );
        }
        if ($inUse && !$this->boolArg($arguments, 'force')) {
            return $this->inUseRefusal(
                sprintf('%s "%s" (#%d)', ucfirst(static::LABEL), $template['code'], $template['id']),
                $usage,
                static::IN_USE_CONSEQUENCE
            );
        }
        $this->deleteTemplate($template['id']);
        $audit = [
            'entity_type' => static::TEMPLATE_TYPE,
            'id' => $template['id'],
            'deleted' => true,
            'in_use' => $inUse,
        ];
        if (!$inUse) {
            return $this->jsonResult(['deleted' => true] + $summary, $audit);
        }
        return $this->jsonResult(
            ['deleted' => true] + $summary + $this->forcedDeleteDetails($usage, static::FORCED_AFTERMATH),
            $audit + ['forced' => true]
        );
    }

    /**
     * Delete the template through the gateway
     *
     * @param int $id
     * @return void
     */
    abstract protected function deleteTemplate(int $id): void;

    /**
     * Preview note on history the delete removes as well (newsletter queues already sent or cancelled)
     *
     * @param array $usage
     * @return string
     * @phpstan-param array<string, mixed> $usage
     */
    private function historyNote(array $usage): string
    {
        $history = Cast::int($usage['queue_history'] ?? 0);
        return $history > 0
            ? sprintf(
                ' Magento also deletes its %d sent or cancelled queue entr%s (newsletter queue history).',
                $history,
                $history === 1 ? 'y' : 'ies'
            )
            : '';
    }

    /**
     * Admin and Hyvä delete permissions of the type
     *
     * @return non-empty-list<string>
     */
    private function deleteResources(): array
    {
        return static::HYVA_DELETE_RESOURCE === ''
            ? [static::ADMIN_DELETE_RESOURCE]
            : [static::ADMIN_DELETE_RESOURCE, static::HYVA_DELETE_RESOURCE];
    }

    /**
     * Refuse a role without one of the delete permissions (the core gate checks one of them per role)
     *
     * @return void
     * @throws AclRefusalException
     */
    private function assertDeletePermissions(): void
    {
        foreach ($this->deleteResources() as $resource) {
            if ($this->callerContext->isAllowed($resource) === false) {
                throw new AclRefusalException(__(
                    'Your admin role may not delete %1: it needs the %2 permission.',
                    static::LABEL_PLURAL,
                    $resource
                ), $resource);
            }
        }
    }
}
