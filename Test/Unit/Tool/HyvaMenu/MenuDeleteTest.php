<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Content\PendingScheduleCleanup;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuDelete;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * T-22: hyva_menu.delete is confirm-gated, says when the menu is still placed and keeps to the store scope. T-73: a
 * menu in use needs "force": true as well. T-68: pending scheduled items are previewed and cancelled with the delete.
 */
class MenuDeleteTest extends TestCase
{
    use StoreScopeStub;

    /**
     * DeletionUsage::forMenu() report of a header menu, also placed twice where the role cannot see.
     */
    private const IN_USE = ['identifier' => 'main', 'used' => true, 'total' => 1, 'design_config' => [[
        'location' => 'header', 'path' => 'hyva_menu/header/identifier', 'scope' => 'stores', 'scope_id' => 1]],
        'hyva_cms_content' => [], 'usages_hidden_by_permissions' => 2];

    public function testAMenuInUseIsPreviewedWithItsPlacementsAndNotDeleted(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('delete');
        $payload = $this->payload($this->tool($gateway, null, self::IN_USE)->execute(['id' => 3]));

        $this->assertSame('delete_menu', $payload['action']);
        $this->assertSame([1], $this->valueAt($payload, 'affected', 0, 'store_ids'));
        $this->assertTrue($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertSame('header', $this->valueAt($payload, 'details', 'usage', 'design_config', 0, 'location'));
        $this->assertStringContainsString('still in use', Cast::string($payload['hint']));
        $this->assertTrue($this->valueAt($payload, 'details', 'force_required'));
    }

    public function testConfirmWithoutForceRefusesAMenuInUse(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('delete');

        $result = $this->tool($gateway, null, self::IN_USE)->execute(['id' => 3, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertSame(409, $payload['status']);
        $this->assertTrue($payload['force_required']);
        $this->assertSame(
            'Menu "main" (#3) is still in use (design_config 1, 2 outside your role, details in usage). Nothing was '
                . 'deleted. To delete it anyway, call again with "confirm": true and "force": true; a placement that '
                . 'names a deleted menu renders nothing.',
            $payload['error']
        );
    }

    public function testConfirmWithForceDeletesAMenuInUse(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('delete')->with(3);

        $payload = $this->payload($this->tool($gateway, null, self::IN_USE)
            ->execute(['id' => 3, 'confirm' => true, 'force' => true]));

        $this->assertTrue($payload['deleted']);
        $this->assertTrue($payload['in_use']);
        // T-79: the result says force was used and which placements broke (the preview's usage report).
        $this->assertTrue($payload['forced']);
        $this->assertSame(self::IN_USE, $payload['usage']);
        $this->assertStringContainsString(
            '(design_config 1, 2 outside your role, details in usage). Those references are now broken',
            Cast::string($payload['hint'])
        );
    }

    public function testConfirmDeletesTheMenu(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('delete')->with(3);

        $payload = $this->payload($this->tool($gateway)->execute(['id' => 3, 'confirm' => true]));

        $this->assertTrue($payload['deleted']);
        $this->assertFalse($payload['in_use']);
        $this->assertArrayNotHasKey('forced', $payload);
    }

    public function testThePreviewListsPendingScheduledItems(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('delete');
        $schedule = $this->scheduleWithPendingItem();
        $schedule->expects($this->never())->method('cancel');

        $payload = $this->payload($this->tool($gateway, null, null, [1], true, $schedule)->execute(['id' => 3]));

        $this->assertSame(
            [[
                'item_id' => 7,
                'release_id' => 2,
                'release_name' => 'Winter',
                'time_scheduled' => '2026-12-01 08:00:00',
            ]],
            $this->valueAt($payload, 'details', 'scheduled_items')
        );
        $this->assertStringContainsString('1 pending scheduled release item(s)', Cast::string($payload['hint']));
        $this->assertStringContainsString('deleting cancels them', Cast::string($payload['hint']));
    }

    public function testThePreviewOfAMenuWithoutScheduledItemsHasNoScheduleDetails(): void
    {
        $payload = $this->payload($this->tool($this->createMock(MenuGatewayInterface::class))->execute(['id' => 3]));

        $this->assertNull($this->valueAt($payload, 'details', 'scheduled_items'));
        $this->assertStringNotContainsString('scheduled', Cast::string($payload['hint']));
    }

    public function testConfirmCancelsThePendingScheduledItems(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('delete')->with(3);
        $schedule = $this->scheduleWithPendingItem();
        $schedule->expects($this->once())->method('cancel')->with([7])
            ->willReturn(['cancelled_items' => [7], 'cancelled_releases' => [2]]);

        $payload = $this->payload(
            $this->tool($gateway, null, null, [1], true, $schedule)->execute(['id' => 3, 'confirm' => true])
        );

        $this->assertTrue($payload['deleted']);
        $this->assertSame([7], $payload['scheduled_items_cancelled']);
        $this->assertSame([2], $payload['scheduled_releases_cancelled']);
    }

    public function testAFailedCancelStillReportsTheDeleteWithAWarning(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('delete')->with(3);
        $schedule = $this->scheduleWithPendingItem();
        $schedule->method('cancel')->willThrowException(new \RuntimeException('Lock wait timeout'));

        $result = $this->tool($gateway, null, null, [1], true, $schedule)->execute(['id' => 3, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertFalse($result->isError());
        $this->assertTrue($payload['deleted']);
        $this->assertSame([], $payload['scheduled_items_cancelled']);
        $this->assertStringContainsString(
            '#7 could not be cancelled (Lock wait timeout)',
            Cast::string($payload['warning'])
        );
    }

    public function testNothingIsCancelledWhenTheDeleteFails(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('delete')->willThrowException(new LocalizedException(
            __('Could not delete')
        ));
        $schedule = $this->scheduleWithPendingItem();
        $schedule->expects($this->never())->method('cancel');

        $this->expectException(LocalizedException::class);
        $this->tool($gateway, null, null, [1], true, $schedule)->execute(['id' => 3, 'confirm' => true]);
    }

    public function testARestrictedRoleMayNotDeleteAMenuOfAllStoreViews(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('delete');

        $result = $this->tool($gateway, [1], null, [0])->execute(['id' => 3, 'confirm' => true]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testItNeedsTheMenuBuilderDeletePermissionAndItsOwnResource(): void
    {
        $this->assertSame('Hyva_MenuBuilder::menu_delete', $this->tool(
            $this->createMock(MenuGatewayInterface::class)
        )->getUnderlyingAclResource());
        $this->assertSame('Magebit_McpHyvaCommerceTools::tool_hyva_menu_delete', MenuDelete::ACL_RESOURCE);
        $this->assertSame(
            WriteMode::WRITE,
            $this->tool($this->createMock(MenuGatewayInterface::class))->getWriteMode()
        );
    }

    public function testItIsHiddenWithoutMenuBuilder(): void
    {
        $this->assertSame(ContentAcl::UNAVAILABLE_RESOURCE, $this->tool(
            $this->createMock(MenuGatewayInterface::class),
            null,
            null,
            [1],
            false
        )->getUnderlyingAclResource());
    }

    /**
     * Menu delete over menu 3 ("main").
     *
     * @param MenuGatewayInterface $gateway
     * @param list<int>|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param array<string, mixed>|null $usage DeletionUsage::forMenu() report; null = unused.
     * @param list<int> $storeIds Store views of the menu.
     * @param bool $menuBuilder Whether Menu Builder is enabled.
     * @param ScheduleService|null $schedule Defaults to one without pending items.
     * @return MenuDelete
     */
    private function tool(
        MenuGatewayInterface $gateway,
        ?array $allowedStoreIds = null,
        ?array $usage = null,
        array $storeIds = [1],
        bool $menuBuilder = true,
        ?ScheduleService $schedule = null
    ): MenuDelete {
        $menu = ['id' => 3, 'identifier' => 'main', 'title' => 'Main menu', 'is_active' => true,
            'store_ids' => $storeIds];
        $guard = $this->storeScope($allowedStoreIds);
        $menus = $this->createMock(MenuService::class);
        // The locator's write check, over the real guard.
        $menus->method('locateForWrite')->willReturnCallback(
            static function () use ($guard, $menu): array {
                $guard->assertChangeable('menu #3', $menu['store_ids'], null);
                return $menu;
            }
        );
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($menuBuilder);
        $features->method('getStatus')->willReturn([
            'feature' => 'menu_builder', 'label' => 'Menu Builder', 'available' => $menuBuilder,
            'installed' => $menuBuilder, 'enabled' => $menuBuilder, 'version' => null, 'package' => '',
            'acl_resource' => null, 'acl_allowed' => null, 'modules' => [],
        ]);
        $deletionUsage = $this->createMock(DeletionUsage::class);
        $deletionUsage->method('forMenu')->willReturn(
            $usage ?? ['identifier' => 'main', 'used' => false, 'total' => 0]
        );
        if ($schedule === null) {
            $schedule = $this->createMock(ScheduleService::class);
            $schedule->method('getItems')->willReturn([]);
            $schedule->expects($this->never())->method('cancel');
        }
        return new MenuDelete($features, $menus, $gateway, $deletionUsage, new PendingScheduleCleanup($schedule));
    }

    /**
     * Schedule service with one pending item (#7 in release #2) for menu 3.
     *
     * @return ScheduleService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function scheduleWithPendingItem(): ScheduleService
    {
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('getItems')->with(
            ['status' => 'pending', 'entity_type' => 'menu', 'entity_id' => 3]
        )->willReturn([[
            'item_id' => 7, 'release_id' => 2, 'entity_type' => 'menu', 'entity_id' => 3, 'name' => 'Main menu',
            'status' => 'pending', 'settings' => null, 'version_history_id' => null,
            'creation_time' => '2026-11-04 10:00:00', 'release_name' => 'Winter', 'release_status' => 'pending',
            'time_scheduled' => '2026-12-01 08:00:00',
        ]]);
        return $schedule;
    }

    /**
     * Decoded JSON payload of a tool result.
     *
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        return $payload;
    }

    /**
     * Value at a key path of a decoded payload, or null.
     *
     * @param mixed $value
     * @param int|string ...$keys
     * @return mixed
     */
    private function valueAt(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        return $value;
    }
}
