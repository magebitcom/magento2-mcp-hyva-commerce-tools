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
use Magebit\McpHyvaCommerceTools\Model\Content\AdminEditorUrl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-29: store rows (published URLs, preview links, is_assigned) only for store views the caller may see.
 */
class PreviewServiceScopeTest extends TestCase
{
    private const ROWS = [
        ['store_id' => 1, 'code' => 'default', 'is_assigned' => true, 'preview_url' => 'p1', 'published_url' => 'u1'],
        ['store_id' => 2, 'code' => 'scope_b', 'is_assigned' => true, 'preview_url' => 'p2', 'published_url' => 'u2'],
    ];

    /**
     * @param list<int>|null $restricted
     * @return PreviewService
     */
    private function service(?array $restricted): PreviewService
    {
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('getStoreContentData')->willReturn(self::ROWS);
        $guard = $this->createMock(StoreScopeGuard::class);
        $guard->method('restrictedStoreIds')->willReturn($restricted);
        return new PreviewService(
            $provider,
            $this->createMock(AdminEditorUrl::class),
            $this->createMock(LoggerInterface::class),
            $guard,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(ContentIndex::class)
        );
    }

    public function testARestrictedCallerOnlyGetsItsStoreViews(): void
    {
        $service = $this->service([2]);

        $this->assertSame([2], array_column($service->getPublishedUrls('cms_page', 5), 'store_id'));
        $preview = $service->getPreview('menu', 3);
        $this->assertSame([2], array_column($preview['stores'], 'store_id'));
        $this->assertSame(1, $preview['stores_hidden_by_permissions'] ?? null);
    }

    public function testAnUnrestrictedCallerGetsEveryStoreView(): void
    {
        $service = $this->service(null);

        $this->assertSame([1, 2], array_column($service->getPublishedUrls('cms_page', 5), 'store_id'));
        $preview = $service->getPreview('menu', 3);
        $this->assertSame([1, 2], array_column($preview['stores'], 'store_id'));
        $this->assertArrayNotHasKey('stores_hidden_by_permissions', $preview);
    }
}
