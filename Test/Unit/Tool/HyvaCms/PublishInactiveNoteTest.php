<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuPublish;
use PHPUnit\Framework\TestCase;

/**
 * T-16 / T-84: the publish reply reports the active flag after publishing; an entity still inactive gets a note
 * naming the call that activates it. Hyvä Menu Builder activates a menu on publish, pages and blocks stay as they are.
 */
class PublishInactiveNoteTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: string|null}>
     */
    public static function entityProvider(): array
    {
        return [
            'inactive menu, activated by publish' => ['menu', false, true, null],
            'inactive page' => ['cms_page', false, false, 'hyva_cms_page_update'],
            'inactive block' => ['cms_block', false, false, 'hyva_cms_block_update'],
            'active menu' => ['menu', true, true, null],
        ];
    }

    /**
     * @dataProvider entityProvider
     * @param string $type
     * @param bool $activeBefore
     * @param bool $activeAfter
     * @param string|null $expected
     */
    public function testTheReplyReportsTheActiveFlagAfterPublishing(
        string $type,
        bool $activeBefore,
        bool $activeAfter,
        ?string $expected
    ): void {
        $row = ['type' => $type, 'id' => 4, 'identifier' => 'main', 'title' => 'Main', 'store_ids' => [0]];
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn(['is_active' => $activeBefore] + $row);
        $locator->expects($this->once())->method('locate')->with($type, ['id' => 4])
            ->willReturn(['is_active' => $activeAfter] + $row);
        $content = $this->createMock(ContentService::class);
        $content->method('getDocuments')->willReturn(['draft' => null, 'published' => null]);
        $content->method('publish')->willReturn(['version_id' => 1, 'version_uid' => 'v1', 'source' => 'draft']);
        $tool = new Publish(
            $locator,
            $content,
            $this->createMock(ComponentTree::class),
            $this->createMock(ContentAcl::class)
        );

        $text = $tool->execute(['entity_type' => $type, 'id' => 4])->getContent()[0]['text'] ?? '';
        $payload = Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));

        $this->assertSame($activeAfter, $payload['is_active'] ?? null);
        if ($expected === null) {
            $this->assertNull($payload['note'] ?? null);
            return;
        }
        $this->assertStringContainsString($expected, Cast::string($payload['note'] ?? ''));
    }

    public function testTheMenuDescriptionsSayPublishingActivatesTheMenu(): void
    {
        foreach ([MenuPublish::class, MenuCreate::class] as $class) {
            $description = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->getDescription();
            $this->assertStringContainsString('activates', $description, $class);
            $this->assertStringNotContainsString('{"is_active": true}', $description, $class);
        }
    }

    public function testMenuCreateNextStepNeedsNoActivationCall(): void
    {
        $payload = $this->menuCreatePayload([]);
        $nextStep = Cast::string($payload['next_step'] ?? '');

        $this->assertStringContainsString('hyva_menu_publish (it also activates the menu)', $nextStep);
        $this->assertStringNotContainsString('hyva_menu_update', $nextStep);
    }

    /**
     * T-96: the menu create reply reports is_active as a boolean, false when the call leaves it out.
     */
    public function testMenuCreateReportsTheActiveFlag(): void
    {
        $this->assertFalse($this->menuCreatePayload([])['is_active'] ?? null);
        $this->assertFalse($this->menuCreatePayload(['is_active' => false])['is_active'] ?? null);
        $this->assertTrue($this->menuCreatePayload(['is_active' => true, 'confirm' => true])['is_active'] ?? null);
    }

    /**
     * Decoded reply of hyva_menu_create for an empty "main" menu, plus the given arguments.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function menuCreatePayload(array $arguments): array
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('create')->willReturn(7);
        $content = $this->createMock(ContentService::class);
        $content->method('validate')->willReturn(['valid' => true, 'schema_error' => null, 'issues' => []]);
        $tool = new MenuCreate(
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

        $text = $tool->execute(
            $arguments + ['title' => 'Main', 'identifier' => 'main', 'template' => 'empty', 'store_ids' => [0]]
        )->getContent()[0]['text'] ?? '';
        return Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));
    }
}
