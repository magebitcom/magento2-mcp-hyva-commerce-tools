<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAdminPerm\Effective;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminPermissionsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\User\Model\UserFactory;

class EffectiveGet extends AbstractTool
{
    public const TOOL_NAME = 'hyva_admin_perm.effective.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_admin_perm_effective_get';
    public const UNDERLYING_ACL_RESOURCE = 'Magento_User::acl_users';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param AdminPermissionsGatewayInterface $permissions
     * @param AdminScopeGuard $scopeGuard
     * @param UserFactory $userFactory
     * @param CallerContext $callerContext
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly AdminPermissionsGatewayInterface $permissions,
        private readonly AdminScopeGuard $scopeGuard,
        private readonly UserFactory $userFactory,
        private readonly CallerContext $callerContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Effective Admin Permissions';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Explain what an admin user can actually do (Hyvä Advanced Admin Permissions): all their roles, roles '
            . 'whose grant has lapsed, negative (deny-wins) roles, each ACL resource allowed or denied with the roles '
            . 'that grant or deny it, and the websites/store views they are restricted to. Read only. Filter the '
            . 'resource list with resource_prefix (e.g. Hyva_Cms::) and allowed.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('user_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('username', fn (StringBuilder $s) => $s->maxLength(40))
            ->string('resource_prefix', fn (StringBuilder $s) => $s->maxLength(100))
            ->boolean('allowed', fn (BooleanBuilder $b) => $b->description('Only allowed (true) or only denied '
                . '(false) resources. Default both.'))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(2000)->description('Default 300.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->getStatus(FeatureCheckerInterface::ADMIN_PERMISSIONS)['enabled']) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::ADMIN_PERMISSIONS)
            );
        }
        $user = $this->userFactory->create();
        $userId = $this->intArg($arguments, 'user_id');
        $username = $this->stringArg($arguments, 'username');
        if ($userId !== null) {
            $user->load($userId);
        } elseif ($username !== null) {
            $user->loadByUsername($username);
        } else {
            return $this->softError('Pass user_id or username.');
        }
        if (!$user->getId()) {
            return $this->softError('No such admin user.');
        }
        $userId = Cast::int($user->getId());
        $effective = $this->permissions->effectivePermissions($userId);
        $prefix = $this->stringArg($arguments, 'resource_prefix');
        $allowed = array_key_exists('allowed', $arguments) ? $this->boolArg($arguments, 'allowed') : null;
        $resources = array_values(array_filter(
            $effective['resources'],
            static fn (array $r): bool => ($prefix === null || str_starts_with($r['id'], $prefix))
                && ($allowed === null || $r['allowed'] === $allowed)
        ));
        $notes = [];
        if ($this->scopeGuard->allowedScopes($this->callerId())['restricted']) {
            $notes[] = 'Your own role is scope-restricted: resources that only work globally show as denied for '
                . 'every user you look up (Hyvä evaluates them with your restriction).';
        }
        return $this->jsonResult([
            'user' => [
                'id' => $userId,
                'username' => (string) $user->getUserName(),
                'is_active' => (bool) $user->getIsActive(),
            ],
            'roles' => $effective['roles'],
            'inactive_roles' => $effective['inactive_roles'],
            'negative_roles' => $effective['negative_roles'],
            'counts' => [
                'allowed' => count(array_filter($effective['resources'], static fn (array $r): bool => $r['allowed'])),
                'resources' => count($effective['resources']),
            ],
            'scopes' => $this->scopeGuard->allowedScopes($userId),
            'resources_total' => count($resources),
            'resources' => array_slice($resources, 0, $this->intArg($arguments, 'limit', 300) ?? 300),
            'notes' => $notes,
        ], ['user_id' => $userId]);
    }

    /**
     * Admin user id of the MCP caller.
     *
     * @return int|null
     */
    private function callerId(): ?int
    {
        return $this->callerContext->adminUserId();
    }
}
