<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-22: the delete preview of a CMS page finds the store views using it as home / 404 page and links to it.
 */
class DeletionUsageTest extends TestCase
{
    public function testAPageIsUsedByItsStoreConfigurationAndByLinks(): void
    {
        $report = $this->usage()->forPage(7, 'home', [1]);

        $this->assertTrue($report['used']);
        $this->assertSame(2, $report['total']);
        // Store view 1 uses "home" (the page is assigned there); store view 2 names another "home" page.
        $this->assertSame(
            [['location' => 'home page', 'path' => 'web/default/cms_home_page', 'scope' => 'stores', 'scope_id' => 1]],
            $report['store_config']
        );
        $this->assertSame([5], array_column(Cast::array($report['links']), 'id'));
    }

    public function testAnUnusedPageIsNotInUse(): void
    {
        $report = $this->usage()->forPage(8, 'about', [1, 2]);

        $this->assertFalse($report['used']);
        $this->assertSame(0, $report['total']);
    }

    public function testAnIdQualifiedConfigValueNamesThePageInEveryStoreView(): void
    {
        // "no-route|9" names page 9 itself, whatever store views it is assigned to.
        $report = $this->usage()->forPage(9, 'no-route', [2]);

        $this->assertSame([1, 2], array_column(Cast::array($report['store_config']), 'scope_id'));
    }

    /**
     * Store view 1: home page "home", 404 "no-route|9"; store view 2: home page "home", 404 "no-route|9". One menu
     * node links to page 7 ("home"), one to page 8 by a custom URL.
     *
     * @return DeletionUsage
     */
    private function usage(): DeletionUsage
    {
        $stores = [];
        foreach ([1, 2] as $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(static fn (string $path): ?string => match ($path) {
            'web/default/cms_home_page' => 'home',
            'web/default/cms_no_route' => 'no-route|9',
            default => null,
        });
        $nodes = [
            ['entity_type' => 'menu', 'id' => 5, 'node' => ['link' => ['type' => 'cms_page', 'value' => 'home']]],
            ['entity_type' => 'menu', 'id' => 6, 'node' => ['link' => ['type' => 'custom_url', 'value' => '8']]],
        ];
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('findNodes')->willReturnCallback(static function (callable $predicate) use ($nodes): array {
            $matches = [];
            foreach ($nodes as $entry) {
                if ($predicate($entry['node'])) {
                    $matches[] = ['entity_type' => $entry['entity_type'], 'id' => $entry['id']];
                }
            }
            return $matches;
        });
        $scope = $this->createMock(ContentScopeFilter::class);
        $scope->method('isRestricted')->willReturn(false);
        $scope->method('filterUsageReport')->willReturnArgument(0);
        return new DeletionUsage(
            $this->createMock(UsageFinder::class),
            $scanner,
            $scope,
            $this->createMock(MenuService::class),
            $config,
            $storeManager
        );
    }
}
