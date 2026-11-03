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
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageGet;
use PHPUnit\Framework\TestCase;

/**
 * T-30: fields accepts the identity keys on their own as well as group names.
 */
class PageGetFieldsTest extends TestCase
{
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
     * @return array<string, mixed>
     */
    private function get(array $fields): array
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
                return $key === 'identity' ? $entity + ['published_urls' => []] : ['group' => $key];
            });
            $resolvers[$key] = $resolver;
        }
        $tool = new PageGet(
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

        $this->assertSame(['status' => ['group' => 'status'], 'title' => 'Home'], $payload);
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
}
