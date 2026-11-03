<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\VersionHistoryAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuGet;
use PHPUnit\Framework\TestCase;

/**
 * T-85: hyva_menu.get fields accepts the settings keys on their own as well as group names, as T-30 does for pages.
 */
class MenuGetFieldsTest extends TestCase
{
    private const ENTITY = [
        'type' => 'menu', 'id' => 6, 'identifier' => 'main', 'title' => 'Main', 'is_active' => true,
        'store_ids' => [0],
    ];

    /**
     * @var list<string>
     */
    private array $resolved = [];

    public function testFlatSettingsKeysComeBackAtTheTopLevel(): void
    {
        $payload = $this->get(['id', 'identifier', 'is_active', 'title']);

        $this->assertSame(['id' => 6, 'identifier' => 'main', 'is_active' => true, 'title' => 'Main'], $payload);
        $this->assertSame(['settings'], $this->resolved, 'only the settings group is built');
    }

    public function testGroupsAndFlatKeysMix(): void
    {
        $payload = $this->get(['structure', 'title']);

        $this->assertSame(['structure' => ['group' => 'structure'], 'title' => 'Main'], $payload);
    }

    public function testGroupNamesStillWork(): void
    {
        $payload = $this->get(['settings']);

        $this->assertSame(['settings'], array_keys($payload));
    }

    public function testUnknownFieldsAreReported(): void
    {
        $payload = $this->get(['name']);

        $this->assertSame(['name'], $payload['unknown_fields'] ?? null);
        $this->assertContains('settings', Cast::array($payload['available_fields'] ?? []));
        $this->assertContains('is_active', Cast::array($payload['available_fields'] ?? []));
    }

    public function testTheDescriptionNamesTheFlatKeys(): void
    {
        $description = $this->tool()->getDescription();

        $this->assertStringContainsString('fields takes group names or the settings keys id, identifier', $description);
    }

    /**
     * Call hyva_menu.get with fields over menu 6.
     *
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function get(array $fields): array
    {
        $this->resolved = [];
        $text = $this->tool()->execute(['id' => 6, 'fields' => $fields])->getContent()[0]['text'] ?? '';
        return Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));
    }

    /**
     * hyva_menu.get with settings, structure and links resolvers that record what they build.
     *
     * @return MenuGet
     */
    private function tool(): MenuGet
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locate')->willReturn(self::ENTITY);
        $resolvers = [];
        foreach (['settings' => 10, 'structure' => 20, 'links' => 30] as $key => $order) {
            $resolver = $this->createMock(ContentFieldResolverInterface::class);
            $resolver->method('getKey')->willReturn($key);
            $resolver->method('getSortOrder')->willReturn($order);
            $resolver->method('resolve')->willReturnCallback(function (array $entity) use ($key): mixed {
                $this->resolved[] = $key;
                return $key === 'settings' ? $entity + ['published_urls' => []] : ['group' => $key];
            });
            $resolvers[$key] = $resolver;
        }
        return new MenuGet(
            $features,
            $this->createMock(MenuService::class),
            $locator,
            new ResolverPipeline(),
            $this->createMock(VersionHistoryAcl::class),
            $resolvers
        );
    }
}
