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
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuUpdate;
use PHPUnit\Framework\TestCase;

class MenuUpdateConfirmTest extends TestCase
{
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

    /**
     * Menu update over an active menu.
     *
     * @param MenuGatewayInterface $gateway
     * @param ContentService $contentService
     * @return MenuUpdate
     */
    private function tool(MenuGatewayInterface $gateway, ContentService $contentService): MenuUpdate
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $menus = $this->createMock(MenuService::class);
        $menus->method('locate')->willReturn(['id' => 3, 'identifier' => 'main', 'title' => 'Main menu',
            'is_active' => true, 'store_ids' => [0]]);
        return new MenuUpdate(
            $features,
            $menus,
            $gateway,
            $contentService,
            $this->createMock(DraftEditor::class),
            new LiveSettings()
        );
    }
}
