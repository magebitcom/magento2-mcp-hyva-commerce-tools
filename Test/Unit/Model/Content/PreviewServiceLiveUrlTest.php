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
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-86: an inactive entity, or a store view it is not assigned to, has no published URL, whatever Hyvä reports.
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
     * Unrestricted service over the given Hyvä store rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return PreviewService
     */
    private function service(array $rows): PreviewService
    {
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('getStoreContentData')->willReturn($rows);
        $guard = $this->createMock(StoreScopeGuard::class);
        $guard->method('restrictedStoreIds')->willReturn(null);
        return new PreviewService(
            $provider,
            $this->createMock(BackendUrl::class),
            $this->createMock(LoggerInterface::class),
            $guard,
            $this->createMock(StoreManagerInterface::class)
        );
    }
}
