<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Acl\VersionHistoryAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content\VersionsResolver;
use PHPUnit\Framework\TestCase;

class VersionsResolverTest extends TestCase
{
    private const PAGE = ['type' => 'cms_page', 'id' => 7, 'identifier' => 'sale', 'title' => 'Sale',
        'is_active' => true, 'store_ids' => [1]];

    public function testWithoutVersionHistoryNothingIsRead(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->expects($this->never())->method('getVersions');

        $this->assertNull($this->resolver($contentService, false)->resolve(self::PAGE, []));
    }

    public function testWithVersionHistoryTheRecentVersionsComeBack(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->expects($this->once())->method('getVersions')->with('cms_page', 7)
            ->willReturn([['version_id' => 3]]);

        $this->assertSame([['version_id' => 3]], $this->resolver($contentService, true)->resolve(self::PAGE, []));
    }

    /**
     * Resolver for a caller with or without the page's version_history permission.
     *
     * @param ContentService $contentService
     * @param bool $allowed
     * @return VersionsResolver
     */
    private function resolver(ContentService $contentService, bool $allowed): VersionsResolver
    {
        $acl = $this->createMock(VersionHistoryAcl::class);
        $acl->method('isAllowed')->with('cms_page')->willReturn($allowed);
        return new VersionsResolver($contentService, $acl);
    }
}
