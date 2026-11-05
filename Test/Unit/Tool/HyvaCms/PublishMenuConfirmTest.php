<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuPublish;
use PHPUnit\Framework\TestCase;

/**
 * T-165: publishing an inactive menu activates it, so it needs confirm, as creating an active menu does.
 */
class PublishMenuConfirmTest extends TestCase
{
    /**
     * Entity type of the last tool() call.
     *
     * @var string
     */
    private string $type = 'menu';

    public function testAnInactiveMenuWithoutConfirmPreviewsTheActivation(): void
    {
        $content = $this->content();
        $content->expects($this->never())->method('publish');

        $payload = $this->payload($this->tool('menu', false, $content), ['version_id' => 9]);

        $this->assertTrue($payload['dry_run'] ?? null);
        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertSame('publish_activate', $payload['action'] ?? null);
        $this->assertIsArray($payload['affected'] ?? null);
        $affected = $payload['affected'][0] ?? null;
        $this->assertIsArray($affected);
        $this->assertSame(['menu', 4, 'main', [2], 9], [
            $affected['entity_type'] ?? null,
            $affected['id'] ?? null,
            $affected['identifier'] ?? null,
            $affected['store_ids'] ?? null,
            $affected['version_id'] ?? null,
        ]);
        $this->assertSame(
            [['field' => 'is_active', 'from' => false, 'to' => true, 'effect' => 'Goes live on the storefront.']],
            $affected['changes'] ?? null
        );
        $hint = Cast::string($payload['hint'] ?? '');
        $this->assertStringStartsWith('Nothing was changed. Call again with "confirm": true to apply.', $hint);
        $this->assertStringContainsString('activates a menu when it publishes it', $hint);
    }

    public function testAnInactiveMenuWithConfirmIsPublished(): void
    {
        $content = $this->content();
        $content->expects($this->once())->method('publish')->with('menu', 4, null);

        $payload = $this->payload($this->tool('menu', false, $content), ['confirm' => true]);

        $this->assertTrue($payload['published'] ?? null);
        $this->assertArrayNotHasKey('dry_run', $payload);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function noConfirmProvider(): array
    {
        return [
            'active menu' => ['menu', true],
            'inactive page' => ['cms_page', false],
            'inactive block' => ['cms_block', false],
        ];
    }

    /**
     * @dataProvider noConfirmProvider
     * @param string $type
     * @param bool $active
     */
    public function testPublishingThatActivatesNothingNeedsNoConfirm(string $type, bool $active): void
    {
        $content = $this->content();
        $content->expects($this->once())->method('publish');

        $this->assertTrue($this->payload($this->tool($type, $active, $content), [])['published'] ?? null);
    }

    public function testBothPublishToolsOfferConfirm(): void
    {
        foreach ([Publish::class, MenuPublish::class] as $class) {
            $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $properties = $tool->getInputSchema()['properties'] ?? [];
            $this->assertIsArray($properties);
            $this->assertArrayHasKey('confirm', $properties, $class);
            $this->assertStringContainsString('needs "confirm": true', $tool->getDescription(), $class);
        }
    }

    /**
     * @return ContentService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function content(): ContentService
    {
        $content = $this->createMock(ContentService::class);
        $content->method('getDocuments')->willReturn(['draft' => null, 'published' => null]);
        $content->method('publish')->willReturn(['version_id' => 1, 'version_uid' => 'v1', 'source' => 'draft']);
        return $content;
    }

    /**
     * Publish tool over entity #4 "main" in store view 2 of the given type and active flag.
     *
     * @param string $type
     * @param bool $active
     * @param ContentService $content
     * @return Publish
     */
    private function tool(string $type, bool $active, ContentService $content): Publish
    {
        $this->type = $type;
        $row = ['type' => $type, 'id' => 4, 'identifier' => 'main', 'title' => 'Main', 'store_ids' => [2]];
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn(['is_active' => $active] + $row);
        $locator->method('locate')->willReturn(['is_active' => true] + $row);
        return new Publish(
            $locator,
            $content,
            $this->createMock(ComponentTree::class),
            $this->createMock(ContentAcl::class),
            new LiveSettings()
        );
    }

    /**
     * @param Publish $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function payload(Publish $tool, array $arguments): array
    {
        $result = $tool->execute(['entity_type' => $this->type, 'id' => 4] + $arguments);
        $text = $result->getContent()[0]['text'] ?? '';
        return Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));
    }
}
