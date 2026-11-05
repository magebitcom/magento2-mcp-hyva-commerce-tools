<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Dashboard;

use Hyva\AdminDashboardFramework\Api\V1\View\ViewInterface;
use Hyva\AdminDashboardFramework\Api\V1\View\ViewRepositoryInterface;
use Hyva\AdminDashboardFramework\Api\V1\ViewRole\ViewRoleRepositoryInterface;
use Hyva\AdminDashboardFramework\Model\View\ActiveViewResolver;
use Hyva\AdminDashboardFramework\Model\View\ViewAccessControl;
use Hyva\AdminDashboardFramework\Model\View\ViewListProvider;
use Hyva\AdminDashboardFramework\Model\WidgetConfig;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\DashboardCatalog;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\User\Model\User;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;

class DashboardCatalogTest extends TestCase
{
    /**
     * @var bool
     */
    private bool $viewShared = false;

    public function testWidgetTypesFollowTheCallersAcl(): void
    {
        $types = $this->catalog()->widgets(4, null)['types'];
        $this->assertSame(['sales', 'no_acl'], array_column($types, 'id'));
        $this->assertSame('Magento_Backend::admin', $types[1]['acl'], 'default widget ACL');
    }

    public function testMcpActivityNeedsTheAuditResourceAsWell(): void
    {
        // T-151: the widget renders only with Magebit_Mcp::mcp_audit (McpActivity::isAllowed()), so it is not listed
        // for a role that holds the widget resource alone.
        $types = $this->catalog(['Magento_Backend::system', 'Magebit_Mcp::mcp_audit'], true)->widgets(4, null)['types'];
        $this->assertNotContains('magebit_mcp_activity_widget', array_column($types, 'id'));

        $types = $this->catalog(['Magento_Backend::system'], true)->widgets(4, null)['types'];
        $this->assertContains('magebit_mcp_activity_widget', array_column($types, 'id'));
    }

    public function testAViewThatIsNotSharedIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->catalog()->widgets(4, 1);
    }

    public function testViewsAreListedOnceWithTheirSharing(): void
    {
        $views = $this->catalog()->views(4);
        $this->assertSame([1], array_column($views['views'], 'id'), 'owned and shared view listed once');
        $this->assertSame('everyone', $views['views'][0]['sharing']);
        $this->assertTrue($views['views'][0]['owner']);
    }

    /**
     * @param list<string> $denied Resources the caller lacks.
     * @param bool $withActivity Whether the MCP Activity widget type is configured.
     * @return DashboardCatalog
     */
    private function catalog(array $denied = ['Magento_Backend::system'], bool $withActivity = false): DashboardCatalog
    {
        $view = $this->createMock(ViewInterface::class);
        $view->method('getViewId')->willReturn(1);
        $view->method('getCreatedBy')->willReturn(4);
        $view->method('getName')->willReturn('Main');
        $view->method('getSharingMode')->willReturn(ViewInterface::SHARING_MODE_EVERYONE);
        $lists = $this->createMock(ViewListProvider::class);
        $lists->method('getViewsByCreator')->willReturn([$view]);
        $lists->method('getSharedViewsForUser')->willReturn([$view]);
        $access = $this->createMock(ViewAccessControl::class);
        $access->method('canViewById')->willReturnCallback(fn (): bool => $this->viewShared);
        $config = $this->createMock(WidgetConfig::class);
        $pool = ['sales' => [], 'secret' => [], 'no_acl' => []];
        $config->method('get')->willReturn($withActivity ? $pool + ['magebit_mcp_activity_widget' => []] : $pool);
        $config->method('getWidgetDataById')->willReturnCallback(static fn (string $id): array => match ($id) {
            'sales' => ['title' => 'Sales', 'acl' => 'Magento_Sales::sales'],
            'secret' => ['title' => 'Secret', 'acl' => 'Magento_Backend::system'],
            'magebit_mcp_activity_widget' => [
                'title' => 'MCP Activity',
                'acl' => 'Magebit_McpHyvaCommerceTools::widget_mcp_activity',
            ],
            default => ['title' => 'Plain'],
        });
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => !in_array($resource, $denied, true)
        );
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);
        $user->method('getRoles')->willReturn([1]);
        $users = $this->createMock(UserFactory::class);
        $users->method('create')->willReturn($user);
        return new DashboardCatalog(
            $lists,
            $access,
            $this->createMock(ActiveViewResolver::class),
            $this->createMock(ViewRepositoryInterface::class),
            $this->createMock(ViewRoleRepositoryInterface::class),
            $config,
            $authorization,
            $this->createMock(ResourceConnection::class),
            $users,
            $this->createMock(UserResource::class)
        );
    }
}
