<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\AdminPermissions;

use Magento\Framework\Exception\LocalizedException;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\AdvancedAdminPermissions\Model\EffectivePermissions;
use Hyva\AdvancedAdminPermissions\Model\ResourceModel\Audit\RoleAssignmentAudit\CollectionFactory;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminPermissionsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Adapter over Hyvä Advanced Admin Permissions 1.0.x (permissions part).
 *
 * EffectivePermissions evaluates the target's roles without an admin session; the audit log has no repository, so
 * its collection is read as the admin grid does.
 */
class AdminPermissionsGateway implements AdminPermissionsGatewayInterface
{
    /**
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices
    ) {
    }

    /**
     * @inheritDoc
     */
    public function effectivePermissions(int $userId): array
    {
        $result = $this->effectivePermissionsService()->getForUser($userId);
        $resources = [];
        foreach ($result['resources'] as $resource) {
            $resources[] = [
                'id' => $resource['id'],
                'title' => $resource['title'],
                'level' => $resource['level'],
                'allowed' => $resource['allowed'],
                'granted_by' => array_values(array_map('intval', $resource['granted_by'])),
                'denied_by' => array_values(array_map('intval', $resource['denied_by'])),
            ];
        }
        return [
            'roles' => $result['roles'],
            'inactive_roles' => array_values(array_map('intval', $result['inactive_roles'])),
            'negative_roles' => array_values(array_map('intval', $result['negative_roles'])),
            'resources' => $resources,
        ];
    }

    /**
     * @inheritDoc
     */
    public function audit(array $filters, int $page, int $pageSize): array
    {
        $collection = $this->auditCollectionFactory()->create();
        foreach (['target_user_id', 'actor_user_id'] as $field) {
            if (isset($filters[$field])) {
                $collection->addFieldToFilter($field, ['eq' => $filters[$field]]);
            }
        }
        if (!empty($filters['action'])) {
            $collection->addFieldToFilter('action', ['in' => $filters['action']]);
        }
        if (isset($filters['from'])) {
            $collection->addFieldToFilter('created_at', ['gteq' => $filters['from']]);
        }
        if (isset($filters['to'])) {
            $collection->addFieldToFilter('created_at', ['lteq' => $filters['to']]);
        }
        $collection->setOrder('created_at', 'DESC')->setCurPage($page)->setPageSize($pageSize);
        $total = $collection->getSize();
        if (($page - 1) * $pageSize >= $total) {
            // Magento clamps a page past the end to the last page; report it as empty instead.
            return ['total' => $total, 'items' => []];
        }
        $items = [];
        foreach ($collection->getItems() as $entry) {
            $items[] = [
                'id' => Cast::int($entry->getData('entity_id')),
                'action' => Cast::string($entry->getData('action')),
                'target_type' => Cast::string($entry->getData('target_type')),
                'target_user_id' => Cast::int($entry->getData('target_user_id')),
                'target_username' => Cast::string($entry->getData('target_username')),
                'role_id' => Cast::int($entry->getData('role_id')),
                'role_name' => Cast::string($entry->getData('role_name')),
                'actor_user_id' => Cast::int($entry->getData('actor_user_id')),
                'actor_username' => Cast::string($entry->getData('actor_username')),
                'detail' => Cast::string($entry->getData('detail')),
                'created_at' => Cast::string($entry->getData('created_at')),
            ];
        }
        return ['total' => $total, 'items' => $items];
    }

    /**
     * EffectivePermissions from the optional package, created on first use.
     *
     * @return EffectivePermissions
     * @throws LocalizedException When the package is not installed.
     */
    private function effectivePermissionsService(): EffectivePermissions
    {
        return $this->optionalServices->get(EffectivePermissions::class);
    }

    /**
     * CollectionFactory from the optional package, created on first use.
     *
     * @return CollectionFactory
     * @throws LocalizedException When the package is not installed.
     */
    private function auditCollectionFactory(): CollectionFactory
    {
        return $this->optionalServices->get(CollectionFactory::class);
    }
}
