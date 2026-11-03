<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Provider as ContentProvider;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T-86: an inactive entity, or a store view it is not assigned to, has no published URL, whatever Hyvä reports.
 * A menu's store views come from the menu itself, as Hyvä's menu provider marks every store view assigned.
 */
class PreviewServiceLiveUrlTest extends TestCase
{
    public function testAnInactivePageHasNoPublishedUrl(): void
    {
        $service = $this->service([
            ['store_id' => 1, 'code' => 'default', 'is_active' => false, 'is_assigned' => true,
                'published_url' => 'https://shop.test/new-page'],
            ['store_id' => 2, 'code' => 'scope_b', 'is_active' => false, 'is_assigned' => true,
                'published_url' => 'https://shop.test/b/new-page'],
        ]);

        $this->assertSame([], $service->getPublishedUrls('cms_page', 5));
        $this->assertSame([null, null], array_column($service->getPreview('cms_page', 5)['stores'], 'published_url'));
    }

    public function testAnActivePageKeepsItsUrlOnlyInTheStoreViewsItIsAssignedTo(): void
    {
        $service = $this->service([
            ['store_id' => 1, 'code' => 'default', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/about'],
            ['store_id' => 2, 'code' => 'scope_b', 'is_active' => true, 'is_assigned' => false,
                'published_url' => 'https://shop.test/b/about'],
        ]);

        $this->assertSame(
            [['store_id' => 1, 'code' => 'default', 'published_url' => 'https://shop.test/about']],
            $service->getPublishedUrls('cms_page', 5)
        );
        $this->assertSame(
            ['https://shop.test/about', null],
            array_column($service->getPreview('cms_page', 5)['stores'], 'published_url')
        );
    }

    public function testARowWithoutTheFlagsKeepsItsUrl(): void
    {
        $service = $this->service([['store_id' => 1, 'code' => 'default', 'published_url' => 'https://shop.test/x']]);

        $this->assertSame([1], array_column($service->getPublishedUrls('cms_page', 5), 'store_id'));
    }

    /**
     * Hyvä's menu provider marks every store view assigned; the menu's own store views decide.
     */
    public function testAMenuHasNoPublishedUrlInAStoreViewItIsNotAssignedTo(): void
    {
        $rows = [
            ['store_id' => 1, 'code' => 'default', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/'],
            ['store_id' => 2, 'code' => 'scope_b', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/b/'],
        ];
        $service = $this->service($rows, [7 => [1]]);

        $this->assertSame([1], array_column($service->getPublishedUrls('menu', 7), 'store_id'));
        $stores = $service->getPreview('menu', 7)['stores'];
        $this->assertSame(['https://shop.test/', null], array_column($stores, 'published_url'));
        $this->assertSame([true, false], array_column($stores, 'is_assigned'));
    }

    public function testAMenuForAllStoreViewsKeepsEveryUrl(): void
    {
        $rows = [
            ['store_id' => 1, 'code' => 'default', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/'],
            ['store_id' => 2, 'code' => 'scope_b', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/b/'],
        ];

        $urls = $this->service($rows, [7 => [0]])->getPublishedUrls('menu', 7);

        $this->assertSame([1, 2], array_column($urls, 'store_id'));
    }

    /**
     * L1: a menu with no store view rows (its only store view was deleted) is shown nowhere by Hyvä's frontend.
     */
    public function testAMenuWithNoStoreViewsHasNoPublishedUrl(): void
    {
        $service = $this->service($this->menuRows(), [7 => []]);

        $this->assertSame([], $service->getPublishedUrls('menu', 7));
        $stores = $service->getPreview('menu', 7)['stores'];
        $this->assertSame([null, null], array_column($stores, 'published_url'));
        $this->assertSame([false, false], array_column($stores, 'is_assigned'));
    }

    /**
     * L1: a menu missing from the index keeps Hyvä's rows.
     */
    public function testAMenuMissingFromTheIndexKeepsHyvasRows(): void
    {
        $service = $this->service($this->menuRows(), []);

        $this->assertSame([1, 2], array_column($service->getPublishedUrls('menu', 7), 'store_id'));
        $this->assertSame([true, true], array_column($service->getPreview('menu', 7)['stores'], 'is_assigned'));
    }

    /**
     * L1: a failed store view lookup is logged and keeps Hyvä's rows.
     */
    public function testAFailedMenuLookupIsLoggedAndKeepsHyvasRows(): void
    {
        $contentIndex = $this->createMock(ContentIndex::class);
        $contentIndex->method('getStoreIds')->willThrowException(new RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Hyvä menu store views could not be read.');

        $urls = $this->service($this->menuRows(), [], $contentIndex, $logger)->getPublishedUrls('menu', 7);

        $this->assertSame([1, 2], array_column($urls, 'store_id'));
    }

    /**
     * Two active Hyvä menu rows, both marked assigned (as Hyvä's menu provider does).
     *
     * @return list<array<string, mixed>>
     */
    private function menuRows(): array
    {
        return [
            ['store_id' => 1, 'code' => 'default', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/'],
            ['store_id' => 2, 'code' => 'scope_b', 'is_active' => true, 'is_assigned' => true,
                'published_url' => 'https://shop.test/b/'],
        ];
    }

    /**
     * Unrestricted service over the given Hyvä store rows.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<int, list<int>> $storeIds Store views per entity id, as ContentIndex::getStoreIds() returns them.
     * @param ContentIndex|null $contentIndex Replaces the index built from $storeIds.
     * @param LoggerInterface|null $logger
     * @return PreviewService
     */
    private function service(
        array $rows,
        array $storeIds = [],
        ?ContentIndex $contentIndex = null,
        ?LoggerInterface $logger = null
    ): PreviewService {
        if ($contentIndex === null) {
            $contentIndex = $this->createMock(ContentIndex::class);
            $contentIndex->method('getStoreIds')->willReturn($storeIds);
        }
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('getStoreContentData')->willReturn($rows);
        $guard = $this->createMock(StoreScopeGuard::class);
        $guard->method('restrictedStoreIds')->willReturn(null);
        return new PreviewService(
            $provider,
            $this->createMock(BackendUrl::class),
            $logger ?? $this->createMock(LoggerInterface::class),
            $guard,
            $this->createMock(StoreManagerInterface::class),
            $contentIndex
        );
    }
}
