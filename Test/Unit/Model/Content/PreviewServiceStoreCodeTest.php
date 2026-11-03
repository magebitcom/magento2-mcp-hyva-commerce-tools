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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-87: menu store rows carry the store view code, which Hyvä's menu provider leaves out.
 */
class PreviewServiceStoreCodeTest extends TestCase
{
    public function testAMissingCodeIsTakenFromTheStoreView(): void
    {
        $service = $this->service([
            ['store_id' => 1, 'is_active' => true, 'is_assigned' => true, 'published_url' => 'https://shop.test/'],
            ['store_id' => 2, 'is_active' => true, 'is_assigned' => true, 'published_url' => null],
        ]);

        $this->assertSame(['default', 'scope_b'], array_column($service->getPreview('menu', 3)['stores'], 'code'));
        $this->assertSame(['default'], array_column($service->getPublishedUrls('menu', 3), 'code'));
    }

    public function testACodeHyvaReportsIsKept(): void
    {
        $service = $this->service([['store_id' => 1, 'code' => 'hyva_code', 'published_url' => null]]);

        $this->assertSame(['hyva_code'], array_column($service->getPreview('cms_page', 5)['stores'], 'code'));
    }

    public function testAnUnknownStoreViewGivesNoCode(): void
    {
        $service = $this->service([['store_id' => 9, 'published_url' => null]]);

        $this->assertSame([null], array_column($service->getPreview('menu', 3)['stores'], 'code'));
    }

    /**
     * Unrestricted service over the given Hyvä store rows; store views 1 and 2 exist.
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
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function (int $id): StoreInterface {
            $codes = [1 => 'default', 2 => 'scope_b'];
            if (!isset($codes[$id])) {
                throw new NoSuchEntityException();
            }
            $store = $this->createMock(StoreInterface::class);
            $store->method('getCode')->willReturn($codes[$id]);
            return $store;
        });
        return new PreviewService(
            $provider,
            $this->createMock(BackendUrl::class),
            $this->createMock(LoggerInterface::class),
            $guard,
            $storeManager
        );
    }
}
