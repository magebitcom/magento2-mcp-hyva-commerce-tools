<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\VersionHistoryAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityGet;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block\BlockGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageGet;
use PHPUnit\Framework\TestCase;

/**
 * T-30: fields accepts the identity keys on their own as well as group names.
 * T-99: it also accepts the status keys has_draft, is_published and draft_differs on their own, as hyva_menu.get does.
 */
class PageGetFieldsTest extends TestCase
{
    private const STATUS = [
        'status' => 'draft', 'has_draft' => true, 'is_published' => false, 'draft_differs' => true, 'legacy' => false,
    ];

    private const ENTITY = [
        'type' => 'cms_page', 'id' => 10, 'identifier' => 'home', 'title' => 'Home', 'is_active' => true,
        'store_ids' => [1],
    ];

    /**
     * @var list<string>
     */
    private array $resolved = [];

    /**
     * @param list<string> $fields
     * @param class-string<AbstractEntityGet> $toolClass
     * @return array<string, mixed>
     */
    private function get(array $fields, string $toolClass = PageGet::class): array
    {
        $this->resolved = [];
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locate')->willReturn(self::ENTITY);
        $resolvers = [];
        foreach (['identity' => 10, 'status' => 20, 'draft' => 30] as $key => $order) {
            $resolver = $this->createMock(ContentFieldResolverInterface::class);
            $resolver->method('getKey')->willReturn($key);
            $resolver->method('getSortOrder')->willReturn($order);
            $resolver->method('resolve')->willReturnCallback(function (array $entity) use ($key): mixed {
                $this->resolved[] = $key;
                return match ($key) {
                    'identity' => $entity + ['published_urls' => []],
                    'status' => self::STATUS,
                    default => ['group' => $key],
                };
            });
            $resolvers[$key] = $resolver;
        }
        $tool = new $toolClass(
            $locator,
            new ResolverPipeline(),
            $this->createMock(VersionHistoryAcl::class),
            $resolvers
        );
        $text = $tool->execute(['id' => 10, 'fields' => $fields])->getContent()[0]['text'] ?? '';
        return Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));
    }

    public function testFlatIdentityKeysComeBackAtTheTopLevel(): void
    {
        $payload = $this->get(['id', 'identifier', 'title', 'store_ids']);

        $this->assertSame(['id' => 10, 'identifier' => 'home', 'title' => 'Home', 'store_ids' => [1]], $payload);
        $this->assertSame(['identity'], $this->resolved, 'only the identity group is built');
    }

    public function testGroupsAndFlatKeysMix(): void
    {
        $payload = $this->get(['status', 'title']);

        $this->assertSame(['status' => self::STATUS, 'title' => 'Home'], $payload);
    }

    public function testGroupNamesStillWork(): void
    {
        $payload = $this->get(['identity']);

        $this->assertSame(['identity'], array_keys($payload));
    }

    public function testUnknownFieldsAreReported(): void
    {
        $payload = $this->get(['name']);

        $this->assertSame(['name'], $payload['unknown_fields'] ?? null);
        $this->assertContains('identity', Cast::array($payload['available_fields'] ?? []));
        $this->assertContains('store_ids', Cast::array($payload['available_fields'] ?? []));
    }

    /**
     * @return array<string, array{class-string<AbstractEntityGet>}>
     */
    public static function toolProvider(): array
    {
        return ['page' => [PageGet::class], 'block' => [BlockGet::class]];
    }

    /**
     * @dataProvider toolProvider
     * @param class-string<AbstractEntityGet> $toolClass
     */
    public function testFlatStatusKeysAccepted(string $toolClass): void
    {
        $payload = $this->get(['has_draft', 'is_published', 'draft_differs'], $toolClass);

        $this->assertArrayNotHasKey('unknown_fields', $payload);
        $this->assertSame(['has_draft' => true, 'is_published' => false, 'draft_differs' => true], $payload);
        $this->assertSame(['status'], $this->resolved, 'only the status group is built');
    }

    /**
     * @dataProvider toolProvider
     * @param class-string<AbstractEntityGet> $toolClass
     */
    public function testIdentityAndStatusKeysMix(string $toolClass): void
    {
        $payload = $this->get(['title', 'has_draft'], $toolClass);

        $this->assertSame(['title' => 'Home', 'has_draft' => true], $payload);
        $this->assertSame(['identity', 'status'], $this->resolved);
    }

    public function testStatusGroupStaysWhenAskedNextToAFlatStatusKey(): void
    {
        $payload = $this->get(['status', 'is_published']);

        $this->assertSame(['status' => self::STATUS, 'is_published' => false], $payload);
    }

    public function testAvailableFieldsNameTheStatusKeys(): void
    {
        $payload = $this->get(['name']);

        $this->assertContains('has_draft', Cast::array($payload['available_fields'] ?? []));
        $this->assertContains('draft_differs', Cast::array($payload['available_fields'] ?? []));
    }

    public function testTheDescriptionNamesTheStatusKeys(): void
    {
        $tool = new PageGet(
            $this->createMock(ContentLocator::class),
            new ResolverPipeline(),
            $this->createMock(VersionHistoryAcl::class),
            []
        );

        $this->assertStringContainsString('has_draft, is_published, draft_differs', $tool->getDescription());
    }
}
