<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaAdminPerm\Audit;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminPermissionsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

class AuditList extends AbstractTool
{
    public const TOOL_NAME = 'hyva_admin_perm.audit.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_admin_perm_audit_list';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AdvancedAdminPermissions::audit';

    private const ACTIONS = ['granted', 'revoked', 'grant_failed', 'revoke_failed', 'made_negative', 'made_ordinary',
        'negative_refused'];
    private const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}( \\d{2}:\\d{2}(:\\d{2})?)?$';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param AdminPermissionsGatewayInterface $permissions
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly AdminPermissionsGatewayInterface $permissions
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::ADMIN_PERMISSIONS)['enabled']
            ? self::UNDERLYING_ACL_RESOURCE
            : 'Magento_User::acl_users';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Admin Role Assignment Audit';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Hyvä Advanced Admin Permissions audit log, newest first: who granted or revoked which role '
            . 'for which admin user or integration, roles flagged as negative, and failed attempts. Filter by '
            . 'target or acting admin, action and date. Read only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('target_user_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('actor_user_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->array('action', fn (ArrayBuilder $a) => $a->ofStrings(fn (StringBuilder $s) => $s->enum(self::ACTIONS))
                ->maxItems(7))
            ->string('from', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN))
            ->string('to', fn (StringBuilder $s) => $s->pattern(self::DATE_PATTERN))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
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
        $filters = [];
        $target = $this->intArg($arguments, 'target_user_id');
        if ($target !== null) {
            $filters['target_user_id'] = $target;
        }
        $actor = $this->intArg($arguments, 'actor_user_id');
        if ($actor !== null) {
            $filters['actor_user_id'] = $actor;
        }
        $actions = array_values(array_intersect(
            array_map([Cast::class, 'string'], Cast::array($arguments['action'] ?? [])),
            self::ACTIONS
        ));
        if ($actions !== []) {
            $filters['action'] = $actions;
        }
        $from = $this->stringArg($arguments, 'from');
        if ($from !== null) {
            $filters['from'] = $from;
        }
        $to = $this->stringArg($arguments, 'to');
        if ($to !== null) {
            $filters['to'] = strlen($to) === 10 ? $to . ' 23:59:59' : $to;
        }
        $result = $this->permissions->audit(
            $filters,
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
