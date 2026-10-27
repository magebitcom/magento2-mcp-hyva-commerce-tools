<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaAdminPerm;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminPermissionsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AdminScopeGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Tool\HyvaAdminPerm\Effective\EffectiveGet;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\User\Model\User;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;

class EffectiveGetTest extends TestCase
{
    public function testFiltersResourcesAndWarnsWhenTheCallerIsRestricted(): void
    {
        $permissions = $this->createMock(AdminPermissionsGatewayInterface::class);
        $permissions->method('effectivePermissions')->willReturn([
            'roles' => [12 => 'Viewer'], 'inactive_roles' => [], 'negative_roles' => [],
            'resources' => [
                $this->resource('Hyva_Cms::page_view', true),
                $this->resource('Hyva_Cms::page_save', false),
                $this->resource('Magento_Sales::sales', true),
            ],
        ]);
        $payload = $this->payload($this->tool($permissions, true)->execute([
            'user_id' => 6, 'resource_prefix' => 'Hyva_Cms::', 'allowed' => false,
        ]));
        $this->assertIsArray($payload['resources']);
        $this->assertIsArray($payload['notes']);
        $this->assertSame(['Hyva_Cms::page_save'], array_column($payload['resources'], 'id'));
        $this->assertSame(['allowed' => 2, 'resources' => 3], $payload['counts']);
        $this->assertCount(1, $payload['notes'], 'scope-restricted caller');
    }

    /**
     * @param AdminPermissionsGatewayInterface $permissions
     * @param bool $callerRestricted
     * @return EffectiveGet
     */
    private function tool(AdminPermissionsGatewayInterface $permissions, bool $callerRestricted): EffectiveGet
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('getStatus')->willReturn(['enabled' => true]);
        $target = $this->createMock(User::class);
                $target->method('getId')->willReturn(6);
        $users = $this->createMock(UserFactory::class);
        $users->method('create')->willReturn($target);
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);
        $scopes = $this->createMock(AdminScopeGatewayInterface::class);
        $scopes->method('allowedScopes')->willReturnCallback(
            static fn (int $userId): array => ['restricted' => $userId === 2 && $callerRestricted,
                'website_ids' => [], 'store_ids' => []]
        );
        $caller = $this->createMock(User::class);
        $caller->method('getId')->willReturn(2);
        $context = new AuthenticatedContextProvider();
        $context->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $caller));
        $caller = new CallerContext($context, $this->createMock(AclChecker::class));
        return new EffectiveGet($features, $permissions, new AdminScopeGuard($modules, $scopes), $users,
            $this->createMock(UserResource::class), $caller);
    }

    /**
     * @param string $id
     * @param bool $allowed
     * @return array{id: string, title: string, level: int, allowed: bool, granted_by: list<int>,
     *     denied_by: list<int>}
     */
    private function resource(string $id, bool $allowed): array
    {
        return [
            'id' => $id, 'title' => $id, 'level' => 2, 'allowed' => $allowed, 'granted_by' => [], 'denied_by' => [],
        ];
    }

    /**
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload, $text);
        return $payload;
    }
}
