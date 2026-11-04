<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Hyva\CmsConnect\Exception\ConflictException;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuUpdate;
use Magento\Framework\Exception\LocalizedException;
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
        $this->assertTrue($payload['dry_run'] ?? null);
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
        $this->assertFalse($payload['dry_run'] ?? null, 'T-152: a write reply says it wrote.');
    }

    public function testRenamingAnInactiveMenuWritesAtOnceAndSaysSo(): void
    {
        // T-152: the title of an inactive menu is not live (README confirm table), so no confirm; the reply has
        // dry_run: false so a caller that meant to preview knows the change was written.
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('update')->with(3, ['title' => 'Main navigation'])
            ->willReturn(['title']);

        $result = $this->tool($gateway, $this->createMock(ContentService::class), null, [], [
            'is_active' => false,
            'is_published' => false,
        ])->execute(['id' => 3, 'settings' => ['title' => 'Main navigation']]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['dry_run'] ?? null);
        $this->assertArrayNotHasKey('confirmation_required', $payload);
        $this->assertSame(['title'], $payload['settings_changed'] ?? null);
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
        $this->assertSame(1, $payload['affected'][0]['children_count'] ?? null);
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

    public function testACallAllowedOnlyByItsMoveMovesTheMenuBeforeSavingTheDraft(): void
    {
        // F2: the menu is assigned to store view 0; the role may change it only by moving it into store view 1.
        $order = [];
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->expects($this->once())->method('saveDraft')->willReturnCallback(
            static function () use (&$order): array {
                $order[] = 'draft';
                return ['version_id' => 2, 'version_uid' => 'v2', 'warnings' => []];
            }
        );
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('update')->with(3, ['store_ids' => [1]])->willReturnCallback(
            static function () use (&$order): array {
                $order[] = 'settings';
                return ['store_ids'];
            }
        );

        $result = $this->tool($gateway, $contentService, [1])->execute([
            'id' => 3,
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'confirm' => true,
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame(['settings', 'draft'], $order);
    }

    public function testAFailedMoveOfACallAllowedOnlyByItsMoveSavesNoMenuDraft(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->expects($this->never())->method('saveDraft');
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('update')->willThrowException(new LocalizedException(__('Identifier already exists')));

        $this->expectException(LocalizedException::class);
        $this->tool($gateway, $contentService, [1])->execute([
            'id' => 3,
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1], 'identifier' => 'taken'],
            'confirm' => true,
        ]);
    }

    public function testAMenuDraftRefusalKnownBeforeTheMoveWritesNothing(): void
    {
        // F4: a stale base_version_uid is refused before the menu is moved, so nothing is written.
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->expects($this->once())->method('assertDraftSavable')
            ->with('menu', 3, [], 'what-i-read')
            ->willThrowException(new ConflictException('Saved elsewhere'));
        $contentService->expects($this->never())->method('saveDraft');
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('update');

        $result = $this->tool($gateway, $contentService, [1])->execute([
            'id' => 3,
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'base_version_uid' => 'what-i-read',
            'confirm' => true,
        ]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertTrue($result->isError());
        $this->assertIsArray($payload);
        $this->assertSame(409, $payload['status'] ?? null);
        $this->assertArrayNotHasKey('settings_applied', $payload);
    }

    public function testARefusedMenuDraftAfterTheMoveReportsTheAppliedSettings(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->method('saveDraft')->willThrowException(new LocalizedException(__('Invalid document')));
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('update')->willReturn(['store_ids']);

        $result = $this->tool($gateway, $contentService, [1])->execute([
            'id' => 3,
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'confirm' => true,
        ]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertTrue($result->isError());
        $this->assertIsArray($payload);
        $this->assertTrue($payload['settings_applied'] ?? null);
        $this->assertFalse($payload['draft_saved'] ?? null);
        $this->assertStringContainsString('Invalid document', Cast::string($payload['error'] ?? ''));
    }

    public function testAFailingMenuSettingsSaveReportsTheAlreadySavedDraft(): void
    {
        // T-70: same soft error as pages and blocks, so the caller knows the draft half was written.
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn([]);
        $contentService->expects($this->once())->method('saveDraft')->willReturn(['version_uid' => 'v1']);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('update')->willThrowException(new LocalizedException(__('Identifier already exists')));

        $result = $this->tool($gateway, $contentService)->execute([
            'id' => 3,
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['preview_url_key' => 'taken'],
        ]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertTrue($result->isError());
        $this->assertIsArray($payload);
        $this->assertTrue($payload['draft_saved'] ?? null);
        $this->assertFalse($payload['settings_applied'] ?? null);
        $this->assertSame(['version_uid' => 'v1'], $payload['draft'] ?? null);
        $this->assertStringContainsString('Identifier already exists', Cast::string($payload['error'] ?? ''));
    }

    public function testAFailingMenuSettingsSaveWithoutADraftStaysAnError(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->expects($this->never())->method('saveDraft');
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('update')->willThrowException(new LocalizedException(__('Identifier already exists')));

        $this->expectException(LocalizedException::class);
        $this->tool($gateway, $contentService)->execute(['id' => 3, 'settings' => ['preview_url_key' => 'taken']]);
    }

    /**
     * T-139: activating an unpublished menu uses the same wording as hyva_menu_create (T-134).
     */
    public function testActivatingAnUnpublishedMenuPreviewsTheMenuCreateWording(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('update');

        $result = $this->tool($gateway, $this->createMock(ContentService::class), null, [], [
            'is_active' => false,
            'is_published' => false,
        ])->execute(['id' => 3, 'settings' => ['is_active' => true]]);
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertSame('is_active', $payload['affected'][0]['field'] ?? null);
        $this->assertSame(LiveSettings::MENU_ACTIVATION_EFFECT, $payload['affected'][0]['effect'] ?? null);
    }

    /**
     * Menu update over an active menu.
     *
     * @param MenuGatewayInterface $gateway
     * @param ContentService $contentService
     * @param array|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param array $dropped Nodes the draft operations discard with their children.
     * @param array $menuFields Menu row fields that replace the defaults (an active, published menu).
     * @return MenuUpdate
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<array<string, mixed>> $dropped
     * @phpstan-param array<string, mixed> $menuFields
     */
    private function tool(
        MenuGatewayInterface $gateway,
        ContentService $contentService,
        ?array $allowedStoreIds = null,
        array $dropped = [],
        array $menuFields = []
    ): MenuUpdate {
        $editor = $this->createMock(DraftEditor::class);
        $editor->method('apply')->willReturn(['document' => [], 'applied' => [], 'dropped' => $dropped]);
        $builder = $this->createMock(MenuItemBuilder::class);
        $builder->method('describe')->willReturnArgument(0);
        $builder->method('describeForRemoval')->willReturnCallback(
            static fn (array $node): array => $node + ['children_count' => count(Cast::array($node['children'] ?? []))]
        );
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menu = array_replace(['id' => 3, 'identifier' => 'main', 'title' => 'Main menu', 'is_active' => true,
            'is_published' => true, 'store_ids' => [0], 'preview_url_key' => null], $menuFields);
        $guard = $this->storeScope($allowedStoreIds);
        $menus = $this->createMock(MenuService::class);
        // The locator's write check, over the real guard.
        $menus->method('locateForWrite')->willReturnCallback(
            static function (array $arguments, ?array $newStoreIds = null) use ($guard, $menu): array {
                $guard->assertChangeable(
                    'menu #3',
                    Cast::intList($menu['store_ids']),
                    $newStoreIds === null ? null : Cast::intList($newStoreIds)
                );
                return $menu;
            }
        );
        $menus->method('changesOnlyAfterReassignment')->willReturnCallback(
            static fn (array $row, ?array $newStoreIds): bool
                => $guard->changesOnlyAfterReassignment(
                    Cast::intList($menu['store_ids']),
                    $newStoreIds === null ? null : Cast::intList($newStoreIds)
                )
        );
        return new MenuUpdate(
            $features,
            $menus,
            $gateway,
            $contentService,
            $editor,
            new LiveSettings(),
            $builder
        );
    }
}
