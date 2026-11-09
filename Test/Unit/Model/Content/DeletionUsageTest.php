<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
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
 * PR #2 review: menus count for the in-use decision even when the caller may not view them, without being listed.
 * T-181: the same report for forms.
 */
class DeletionUsageTest extends TestCase
{
    use ContentScopeStub;

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
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $report);
    }

    public function testAPageLinkedOnlyFromAMenuTheCallerMayNotViewIsStillInUse(): void
    {
        $scanned = [];
        $report = $this->usage(false, $scanned)->forPage(10, 'contact', [1]);

        // Menus are scanned whatever the caller's menu ACL, so the force gate applies.
        $this->assertSame([true], $scanned);
        $this->assertTrue($report['used']);
        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['links']);
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
    }

    public function testACallerAllowedToViewMenusSeesTheMenuLink(): void
    {
        $report = $this->usage()->forPage(10, 'contact', [1]);

        $this->assertTrue($report['used']);
        $this->assertSame(1, $report['total']);
        $this->assertSame([4], array_column(Cast::array($report['links']), 'id'));
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $report);
    }

    public function testABlockPlacedInAMenuTheCallerMayNotViewIsInUseWithTheMenuHidden(): void
    {
        $finder = $this->createMock(UsageFinder::class);
        $finder->expects($this->once())->method('findBlockUsage')->with(3, 'promo', true)->willReturn([
            'hyva_content' => [['entity_type' => ContentLocator::TYPE_MENU, 'id' => 4],
                ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 2]],
            'magento_content' => [],
            'widget_instances' => [],
            'total' => 2,
        ]);

        $report = $this->usage(false, $scanned, $finder)->forBlock(3, 'promo');

        $this->assertTrue($report['used']);
        $this->assertSame(1, $report['total']);
        $this->assertSame([2], array_column(Cast::array($report['hyva_content']), 'id'));
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
    }

    public function testABlockUsedOnlyInAMenuTheCallerMayNotViewIsStillInUse(): void
    {
        $finder = $this->createMock(UsageFinder::class);
        $finder->method('findBlockUsage')->willReturn([
            'hyva_content' => [['entity_type' => ContentLocator::TYPE_MENU, 'id' => 4]],
            'magento_content' => [],
            'widget_instances' => [],
            'total' => 1,
        ]);

        $report = $this->usage(false, $scanned, $finder)->forBlock(3, 'promo');

        $this->assertTrue($report['used']);
        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['hyva_content']);
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
    }

    public function testAScopeRestrictedCallerWithoutMenuViewStillCountsTheMenuAsHidden(): void
    {
        // T-97: the menu exists (Menu Builder installed and enabled) and is in the caller's store view, so the scope
        // filter keeps it and the menu ACL hides it; the in-use decision and the hidden count both see it.
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(false);
        $features->method('isInstalledAndEnabled')->willReturn(true);
        $scope = $this->contentScope(
            [1],
            [ContentLocator::TYPE_MENU => [4 => [1]], ContentLocator::TYPE_PAGE => [2 => [1]]],
            null,
            [],
            null,
            $features
        );
        $finder = $this->createMock(UsageFinder::class);
        $finder->method('findBlockUsage')->willReturn([
            'hyva_content' => [['entity_type' => ContentLocator::TYPE_MENU, 'id' => 4],
                ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 2]],
            'magento_content' => [],
            'widget_instances' => [],
            'total' => 2,
        ]);

        $report = $this->usage(false, $scanned, $finder, $scope)->forBlock(3, 'promo');

        $this->assertTrue($report['used']);
        $this->assertSame(1, $report['total']);
        $this->assertSame([2], array_column(Cast::array($report['hyva_content']), 'id'));
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
    }

    /**
     * T-181: a form embedded only outside the caller's store views is still in use; the usage is counted, not listed.
     */
    public function testAFormEmbeddedOutsideTheCallersStoreViewsIsInUseWithTheUsageHidden(): void
    {
        $scope = $this->contentScope([1], [ContentLocator::TYPE_PAGE => [2 => [1], 7 => [2]]]);
        $finder = $this->createMock(UsageFinder::class);
        $finder->expects($this->once())->method('findFormReferences')->with('contact')->willReturn([
            'hyva_content' => [['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 2],
                ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 7]],
            'magento_content' => [],
            'widget_instances' => [['instance_id' => 21, 'title' => 'Sidebar', 'theme_id' => 5, 'store_ids' => '2']],
            'total' => 3,
        ]);

        $report = $this->usage(true, $scanned, $finder, $scope)->forForm('contact');

        $this->assertTrue($report['used']);
        $this->assertSame(1, $report['total']);
        $this->assertSame([2], array_column(Cast::array($report['hyva_content']), 'id'));
        $this->assertSame([], $report['widget_instances']);
        $this->assertSame(2, $report['usages_hidden_by_permissions']);
    }

    public function testAnUnembeddedFormIsNotInUse(): void
    {
        $finder = $this->createMock(UsageFinder::class);
        $finder->method('findFormReferences')->willReturn(
            ['hyva_content' => [], 'magento_content' => [], 'widget_instances' => [], 'total' => 0]
        );

        $report = $this->usage(true, $scanned, $finder)->forForm('contact');

        $this->assertFalse($report['used']);
        $this->assertSame(0, $report['total']);
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $report);
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
     * node links to page 7 ("home"), one to page 8 by a custom URL, one to page 10 ("contact").
     *
     * @param bool $menuViewAllowed Whether the caller may view menus (Menu Builder "available").
     * @param list<bool>|null $scanned Collects the ignoreMenuAcl argument of every content scan.
     * @param-out list<bool> $scanned
     * @param UsageFinder|null $usageFinder
     * @param ContentScopeFilter|null $scopeFilter Replaces an unrestricted caller's filter.
     * @return DeletionUsage
     */
    private function usage(
        bool $menuViewAllowed = true,
        ?array &$scanned = null,
        ?UsageFinder $usageFinder = null,
        ?ContentScopeFilter $scopeFilter = null
    ): DeletionUsage {
        $scanned = [];
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
            ['entity_type' => 'menu', 'id' => 4, 'node' => ['link' => ['type' => 'cms_page', 'value' => 'contact']]],
        ];
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('findNodes')->willReturnCallback(static function (
            callable $predicate,
            array $types = ContentLocator::TYPES,
            bool $ignoreMenuAcl = false
        ) use (
            $nodes,
            &$scanned
        ): array {
            $scanned[] = $ignoreMenuAcl;
            $matches = [];
            foreach ($nodes as $entry) {
                if ($predicate($entry['node'])) {
                    $matches[] = ['entity_type' => $entry['entity_type'], 'id' => $entry['id']];
                }
            }
            return $matches;
        });
        // An unrestricted caller: the real filter applies only the menu ACL.
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($menuViewAllowed);
        $features->method('isInstalledAndEnabled')->willReturn(true);
        return new DeletionUsage(
            $usageFinder ?? $this->createMock(UsageFinder::class),
            $scanner,
            $scopeFilter ?? $this->contentScope(null, [], null, [], null, $features),
            $this->createMock(MenuService::class),
            $config,
            $storeManager
        );
    }
}
