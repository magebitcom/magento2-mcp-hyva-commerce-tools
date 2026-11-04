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
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuCreate;
use PHPUnit\Framework\TestCase;

/**
 * T-101 (D1): hyva_menu.create with is_active=true needs confirm, as page and block create do.
 */
class MenuCreateConfirmTest extends TestCase
{
    use StoreScopeStub;

    private const ARGUMENTS = ['title' => 'Main', 'identifier' => 'main', 'template' => 'empty', 'store_ids' => [1]];

    public function testActiveCreateWithoutConfirmPreviews(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('create');
        $content = $this->content();
        $content->expects($this->never())->method('saveDraft');

        $payload = $this->payload($this->tool($gateway, $content), ['is_active' => true]);

        $this->assertTrue($payload['dry_run'] ?? null);
        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertSame('create_active', $payload['action'] ?? null);
        $this->assertSame(1, $payload['total'] ?? null);
        $this->assertIsArray($payload['affected'] ?? null);
        $affected = $payload['affected'][0] ?? null;
        $this->assertIsArray($affected);
        $this->assertSame('menu', $affected['entity_type'] ?? null);
        $this->assertSame('main', $affected['identifier'] ?? null);
        $this->assertSame([1], $affected['store_ids'] ?? null);
        $this->assertSame('is_active', $affected['changes'][0]['field'] ?? null);
        // T-134: an active menu renders nothing until it is published (and placed).
        $effect = Cast::string($affected['changes'][0]['effect'] ?? '');
        $this->assertStringNotContainsString('Goes live on the storefront.', $effect);
        $this->assertStringContainsString('once it is published', $effect);
        $this->assertStringContainsString('hyva_menu_publish', $effect);
        $this->assertSame(LiveSettings::MENU_ACTIVATION_EFFECT, $effect, 'one wording shared with hyva_menu_update');
        $this->assertArrayHasKey('hint', $payload);
    }

    public function testActiveCreateWithConfirmSaves(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->once())->method('create')
            ->with($this->callback(static fn (array $data): bool => $data['is_active'] === true))
            ->willReturn(7);
        $content = $this->content();
        $content->expects($this->once())->method('saveDraft')->willReturn(['version_id' => 1]);

        $payload = $this->payload($this->tool($gateway, $content), ['is_active' => true, 'confirm' => true]);

        $this->assertTrue($payload['created'] ?? null);
        $this->assertSame(7, $payload['id'] ?? null);
        $this->assertTrue($payload['is_active'] ?? null);
    }

    public function testInactiveCreateNeedsNoConfirm(): void
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->exactly(2))->method('create')->willReturn(7);
        $content = $this->content();

        $this->assertTrue($this->payload($this->tool($gateway, $content), [])['created'] ?? null);
        $this->assertTrue(
            $this->payload($this->tool($gateway, $content), ['is_active' => false])['created'] ?? null
        );
    }

    public function testTheSchemaAndDescriptionNameTheConfirm(): void
    {
        $tool = $this->tool($this->createMock(MenuGatewayInterface::class), $this->content());
        $properties = $tool->getInputSchema()['properties'] ?? null;
        $this->assertIsArray($properties);
        $this->assertIsArray($properties['confirm'] ?? null);

        $this->assertSame('boolean', $properties['confirm']['type'] ?? null);
        $this->assertStringContainsString('"confirm": true', $tool->getDescription());
        $this->assertStringNotContainsString('"/"', json_encode($properties['preview_url_key'] ?? null) ?: '');
    }

    /**
     * Content service that accepts any document.
     *
     * @return ContentService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function content(): ContentService
    {
        $content = $this->createMock(ContentService::class);
        $content->method('validate')->willReturn(['valid' => true, 'schema_error' => null, 'issues' => []]);
        return $content;
    }

    /**
     * Tool.
     *
     * @param MenuGatewayInterface $gateway
     * @param ContentService $content
     * @return MenuCreate
     */
    private function tool(MenuGatewayInterface $gateway, ContentService $content): MenuCreate
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return new MenuCreate(
            $features,
            $this->createMock(MenuService::class),
            $gateway,
            $content,
            $this->createMock(ComponentTree::class),
            $this->createMock(MenuItemBuilder::class),
            $this->createMock(DraftEditor::class),
            $this->createMock(PreviewService::class),
            $this->storeScope(null),
            new LiveSettings()
        );
    }

    /**
     * Decoded reply for the default arguments plus $arguments.
     *
     * @param MenuCreate $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function payload(MenuCreate $tool, array $arguments): array
    {
        $result = $tool->execute($arguments + self::ARGUMENTS);
        $this->assertFalse($result->isError());
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
