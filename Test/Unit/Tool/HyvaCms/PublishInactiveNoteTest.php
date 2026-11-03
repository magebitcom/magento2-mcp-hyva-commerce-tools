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
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuPublish;
use PHPUnit\Framework\TestCase;

/**
 * T-16: publishing an inactive entity says which call activates it.
 */
class PublishInactiveNoteTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool, 2: string|null}>
     */
    public static function entityProvider(): array
    {
        return [
            'inactive menu' => ['menu', false, 'hyva_menu_update {"settings": {"is_active": true}, "confirm": true}'],
            'inactive page' => ['cms_page', false, 'hyva_cms_page_update'],
            'inactive block' => ['cms_block', false, 'hyva_cms_block_update'],
            'active menu' => ['menu', true, null],
        ];
    }

    /**
     * @dataProvider entityProvider
     * @param string $type
     * @param bool $active
     * @param string|null $expected
     */
    public function testTheNoteNamesTheActivationCall(string $type, bool $active, ?string $expected): void
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn([
            'type' => $type, 'id' => 4, 'identifier' => 'main', 'title' => 'Main', 'is_active' => $active,
            'store_ids' => [0],
        ]);
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

        if ($expected === null) {
            $this->assertNull($payload['note'] ?? null);
            return;
        }
        $this->assertStringContainsString($expected, Cast::string($payload['note'] ?? ''));
    }

    public function testTheMenuDescriptionsSayPublishingDoesNotActivate(): void
    {
        foreach ([MenuPublish::class, MenuCreate::class] as $class) {
            $description = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->getDescription();
            $this->assertStringContainsString(
                'hyva_menu_update {"settings": {"is_active": true}, "confirm": true}',
                $description,
                $class
            );
        }
    }
}
