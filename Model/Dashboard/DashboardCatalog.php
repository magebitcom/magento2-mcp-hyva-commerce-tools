<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\View\ViewInterface;
use Hyva\AdminDashboardFramework\Api\V1\View\ViewRepositoryInterface;
use Hyva\AdminDashboardFramework\Api\V1\ViewRole\ViewRoleRepositoryInterface;
use Hyva\AdminDashboardFramework\Model\View\ActiveViewResolver;
use Hyva\AdminDashboardFramework\Model\View\ViewAccessControl;
use Hyva\AdminDashboardFramework\Model\View\ViewListProvider;
use Hyva\AdminDashboardFramework\Model\WidgetConfig;
use Hyva\AdminDashboardFramework\Model\Config\Widget\Converter;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\User\Model\UserFactory;

/**
 * Hyvä Admin Dashboard views and widgets as the MCP admin would see them.
 *
 * The dashboard's own view model, instance filter and widget auth read the admin session, which an MCP request does
 * not have; this uses the explicit-id services instead (ViewListProvider, ViewAccessControl, the read-only
 * ActiveViewResolver::resolve()) and checks widget types at type level only (isAllowed with no instance).
 */
class DashboardCatalog
{
    /**
     * The dashboard's default widget ACL (framework etc/di.xml).
     */
    private const DEFAULT_WIDGET_ACL = 'Magento_Backend::admin';

    private const SHARING = [
        ViewInterface::SHARING_MODE_PRIVATE => 'private',
        ViewInterface::SHARING_MODE_EVERYONE => 'everyone',
        ViewInterface::SHARING_MODE_SELECTED => 'selected_roles',
    ];

    /**
     * @param ViewListProvider $viewListProvider
     * @param ViewAccessControl $viewAccessControl
     * @param ActiveViewResolver $activeViewResolver
     * @param ViewRepositoryInterface $viewRepository
     * @param ViewRoleRepositoryInterface $viewRoleRepository
     * @param WidgetConfig $widgetConfig
     * @param AuthorizationInterface $authorization
     * @param ResourceConnection $resourceConnection
     * @param UserFactory $userFactory
     */
    public function __construct(
        private readonly ViewListProvider $viewListProvider,
        private readonly ViewAccessControl $viewAccessControl,
        private readonly ActiveViewResolver $activeViewResolver,
        private readonly ViewRepositoryInterface $viewRepository,
        private readonly ViewRoleRepositoryInterface $viewRoleRepository,
        private readonly WidgetConfig $widgetConfig,
        private readonly AuthorizationInterface $authorization,
        private readonly ResourceConnection $resourceConnection,
        private readonly UserFactory $userFactory
    ) {
    }

    /**
     * Views the admin owns or that are shared with them, and which one opens by default
     *
     * @param int $adminId
     * @return array
     * @phpstan-return array{active_view_id: int|null, views: list<array{id: int, name: string, owner: bool,
     *     sharing: string, role_ids: list<int>, updated_at: string}>}
     */
    public function views(int $adminId): array
    {
        $roleIds = $this->roleIds($adminId);
        $views = [];
        foreach (array_merge(
            $this->viewListProvider->getViewsByCreator($adminId),
            $this->viewListProvider->getSharedViewsForUser($roleIds)
        ) as $view) {
            if ($view instanceof ViewInterface && !isset($views[$view->getViewId()])) {
                $views[$view->getViewId()] = $view;
            }
        }
        $roles = $this->viewRoleRepository->getRoleIdsByViewIds(array_keys($views));
        $rows = [];
        foreach ($views as $id => $view) {
            $rows[] = [
                'id' => $id,
                'name' => (string) $view->getName(),
                'owner' => $view->getCreatedBy() === $adminId,
                'sharing' => self::SHARING[$view->getSharingMode()] ?? 'unknown',
                'role_ids' => Cast::intList($roles[$id] ?? []),
                'updated_at' => (string) $view->getUpdatedAt(),
            ];
        }
        $active = $this->activeViewResolver->resolve($adminId, $roleIds);
        return ['active_view_id' => $active?->getViewId(), 'views' => $rows];
    }

    /**
     * Widget types the admin's role may use, and the widgets placed on a view the admin can see
     *
     * @param int $adminId
     * @param int|null $viewId
     * @return array
     * @throws LocalizedException When the view does not exist or is not visible to the admin.
     * @phpstan-return array{types: list<array{id: string, title: string, category: string, acl: string}>,
     *     placed: list<array{instance_id: int, type: string, created_by: int}>}
     */
    public function widgets(int $adminId, ?int $viewId): array
    {
        $types = [];
        $pool = $this->widgetConfig->get(Converter::CONFIG_KEY_POOL);
        foreach (array_keys(is_array($pool) ? $pool : []) as $id) {
            $data = $this->widgetConfig->getWidgetDataById((string) $id);
            $acl = Cast::string($data['acl'] ?? '') ?: self::DEFAULT_WIDGET_ACL;
            if (!$this->authorization->isAllowed($acl)) {
                continue;
            }
            $types[(string) $id] = [
                'id' => (string) $id,
                'title' => Cast::string($data['title'] ?? $id),
                'category' => Cast::string($data['category'] ?? ''),
                'acl' => $acl,
            ];
        }
        $placed = [];
        if ($viewId !== null) {
            try {
                $this->viewRepository->getById($viewId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('There is no dashboard view %1.', $viewId));
            }
            if (!$this->viewAccessControl->canViewById($viewId, $adminId, $this->roleIds($adminId))) {
                throw new LocalizedException(__('Dashboard view %1 is not shared with you.', $viewId));
            }
            // The instance repository builds the widget dispatcher (adminhtml-only wiring): read the rows directly.
            $connection = $this->resourceConnection->getConnection();
            $rows = $connection->fetchAll($connection->select()
                ->from(
                    $this->resourceConnection->getTableName('hyva_admin_dashboard_widget_instance'),
                    ['instance_id', 'widget_type_id', 'created_by']
                )
                ->where('view_id = ?', $viewId)
                ->order('instance_id ASC'));
            foreach ($rows as $row) {
                $type = Cast::string($row['widget_type_id'] ?? '');
                if (isset($types[$type])) {
                    $placed[] = [
                        'instance_id' => Cast::int($row['instance_id'] ?? 0),
                        'type' => $type,
                        'created_by' => Cast::int($row['created_by'] ?? 0),
                    ];
                }
            }
        }
        return ['types' => array_values($types), 'placed' => $placed];
    }

    /**
     * Role ids of the admin, as the dashboard controllers use them (core User::getRoles()).
     *
     * @param int $adminId
     * @return list<int>
     */
    private function roleIds(int $adminId): array
    {
        $user = $this->userFactory->create();
        $user->load($adminId);
        return $user->getId() ? array_values(array_map('intval', $user->getRoles())) : [];
    }
}
