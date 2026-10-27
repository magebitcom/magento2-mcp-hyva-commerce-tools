<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuUpdate;
use PHPUnit\Framework\TestCase;

class MenuUpdateConfirmTest extends TestCase
{
    use StoreScopeStub;

    public function testARestrictedRoleMayNotAssignAForeignStoreView(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('update');

        $result = $this->tool($gateway, $this->createMock(ContentService::class), [1])->execute([
            'id' => 3,
            'settings' => ['store_ids' => [2]],
            'confirm' => true,
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testRenamingAnActiveMenuWithoutConfirmWritesNothing(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('update');
        $contentService = $this->createMock(ContentService::class);
        $contentService->expects($this->never())->method('saveDraft');

        $result = $this->tool($gateway, $contentService)->execute([
            'id' => 3,
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['title' => 'Main navigation'],
        ]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['confirmation_required'] ?? null);
    }

    public function testConfirmAppliesTheSetting(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('update')->with(3, ['title' => 'Main navigation'])
            ->willReturn(['title']);

        $result = $this->tool($gateway, $this->createMock(ContentService::class))->execute([
            'id' => 3,
            'settings' => ['title' => 'Main navigation'],
            'confirm' => true,
        ]);

        $this->assertFalse($result->isError());
    }

    public function testPreviewUrlKeyOfAnActiveMenuNeedsNoConfirm(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('update')->with(3, ['preview_url_key' => 'contact'])
            ->willReturn(['preview_url_key']);

        $result = $this->tool($gateway, $this->createMock(ContentService::class))->execute([
            'id' => 3,
            'settings' => ['preview_url_key' => 'contact'],
        ]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertIsArray($payload);
        $this->assertSame(['preview_url_key'], $payload['settings_changed'] ?? null);
    }

    public function testRemovingAnItemWithChildrenWithoutConfirmPreviewsTheChildrenAndSavesNothing(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->expects($this->never())->method('saveDraft');
        $dropped = ['uid' => 'p1', 'component' => 'menu_item', 'label' => 'Shop',
            'children' => [['uid' => 'c1', 'component' => 'menu_item', 'label' => 'Shirts']]];

        $result = $this->tool($this->createMock(MenuGatewayInterface::class), $contentService, null, [$dropped])
            ->execute(['id' => 3, 'operations' => [['op' => 'remove', 'uid' => 'p1']]]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertSame('remove_menu_item_with_children', $payload['action'] ?? null);
        $this->assertSame('c1', $payload['affected'][0]['children'][0]['uid'] ?? null);
    }

    public function testConfirmSavesARemovalOfAnItemWithChildren(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->expects($this->once())->method('saveDraft')->willReturn([]);
        $dropped = ['uid' => 'p1', 'component' => 'menu_item', 'children' => [['uid' => 'c1']]];

        $result = $this->tool($this->createMock(MenuGatewayInterface::class), $contentService, null, [$dropped])
            ->execute(['id' => 3, 'operations' => [['op' => 'remove', 'uid' => 'p1']], 'confirm' => true]);

        $this->assertFalse($result->isError());
    }

    /**
     * Menu update over an active menu.
     *
     * @param MenuGatewayInterface $gateway
     * @param ContentService $contentService
     * @param array|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param array $dropped Nodes the draft operations discard with their children.
     * @return MenuUpdate
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<array<string, mixed>> $dropped
     */
    private function tool(
        MenuGatewayInterface $gateway,
        ContentService $contentService,
        ?array $allowedStoreIds = null,
        array $dropped = []
    ): MenuUpdate {
        $editor = $this->createMock(DraftEditor::class);
        $editor->method('apply')->willReturn(['document' => [], 'applied' => [], 'dropped' => $dropped]);
        $builder = $this->createMock(MenuItemBuilder::class);
        $builder->method('describe')->willReturnArgument(0);
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->method('locateForWrite')->willReturn(['id' => 3, 'identifier' => 'main', 'title' => 'Main menu',
            'is_active' => true, 'store_ids' => [0], 'preview_url_key' => null]);
        return new MenuUpdate(
            $features,
            $menus,
            $gateway,
            $contentService,
            $editor,
            new LiveSettings(),
            $this->storeScope($allowedStoreIds),
            $builder
        );
    }
}
