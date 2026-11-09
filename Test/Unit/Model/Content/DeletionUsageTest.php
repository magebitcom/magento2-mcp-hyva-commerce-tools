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
 * T-181: the same report for forms. T-183: a form using the block as its success content block is a usage, hidden
 * from a caller outside the form's store views or not allowed to view forms, and still in use for both.
 * T-184: a form whose success redirect URL links to the page is a page usage, with the same hiding.
 */
class DeletionUsageTest extends TestCase
{
    use ContentScopeStub;

    /**
     * Form roots of forms 21-28 as findFormNodes() sees them, with the form's store views.
     */
    private const REDIRECT_FORMS = [
        ['id' => 21, 'store_ids' => [1], 'node' => ['success_redirect_url' => ['type' => 'cms_page', 'value' => 'thanks',
            'label' => 'Thanks', 'src' => '', 'open_in_new_tab' => false]]],
        ['id' => 22, 'store_ids' => [2], 'node' => ['success_redirect_url' => ['type' => 'cms_page',
            'value' => '/thanks']]],
        ['id' => 23, 'store_ids' => [0], 'node' => ['success_redirect_url' => ['type' => 'cms_page', 'value' => 12]]],
        ['id' => 24, 'store_ids' => [1], 'node' => ['success_redirect_url' => ['type' => 'custom_url',
            'value' => 'thanks']]],
        ['id' => 25, 'store_ids' => [1], 'node' => ['success_redirect_url' => ['type' => 'cms_page',
            'value' => 'other']]],
        ['id' => 26, 'store_ids' => [1], 'node' => ['success_redirect_url' => null]],
        ['id' => 27, 'store_ids' => [1], 'node' => ['success_content_block' => 'thanks']],
        ['id' => 28, 'store_ids' => [1], 'node' => ['link' => ['type' => 'cms_page', 'value' => 'thanks']]],
    ];

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

    public function testAFormOutsideTheCallersStoreViewsKeepsTheBlockInUseWithTheFormHidden(): void
    {
        $scope = $this->contentScope([1], [ContentLocator::TYPE_PAGE => [2 => [1]]]);
        $finder = $this->createMock(UsageFinder::class);
        $finder->method('findBlockUsage')->willReturn([
            'hyva_content' => [['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 2],
                ['entity_type' => ContentScanner::TYPE_FORM, 'id' => 8, 'store_ids' => [2]],
                ['entity_type' => ContentScanner::TYPE_FORM, 'id' => 9, 'store_ids' => [0]]],
            'magento_content' => [],
            'widget_instances' => [],
            'total' => 3,
        ]);

        $report = $this->usage(true, $scanned, $finder, $scope)->forBlock(3, 'promo');

        $this->assertTrue($report['used']);
        $this->assertSame(2, $report['total']);
        $this->assertSame([2, 9], array_column(Cast::array($report['hyva_content']), 'id'));
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
    }

    public function testABlockUsedOnlyByAFormTheCallerMayNotViewIsStillInUse(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturnCallback(
            static fn (string $feature): bool => $feature !== FeatureCheckerInterface::FORM_BUILDER
        );
        $features->method('isInstalledAndEnabled')->willReturn(true);
        $finder = $this->createMock(UsageFinder::class);
        $finder->method('findBlockUsage')->willReturn([
            'hyva_content' => [['entity_type' => ContentScanner::TYPE_FORM, 'id' => 8, 'store_ids' => [1]]],
            'magento_content' => [],
            'widget_instances' => [],
            'total' => 1,
        ]);
        $scope = $this->contentScope(null, [], null, [], null, $features);

        $report = $this->usage(true, $scanned, $finder, $scope)->forBlock(3, 'promo');

        $this->assertTrue($report['used']);
        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['hyva_content']);
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
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

    public function testAFormRedirectingToThePageIsALinkUsage(): void
    {
        $report = $this->usage(forms: self::REDIRECT_FORMS)->forPage(12, 'thanks', [1]);

        $this->assertTrue($report['used']);
        // Identifier, identifier with a leading slash and page id; not a custom URL, another page, null or absent.
        $this->assertSame(3, $report['total']);
        $this->assertSame([21, 22, 23], array_column(Cast::array($report['links']), 'id'));
        $this->assertSame(
            [ContentScanner::TYPE_FORM],
            array_values(array_unique(array_column(Cast::array($report['links']), 'entity_type')))
        );
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $report);
    }

    public function testAFormRedirectOutsideTheCallersStoreViewsKeepsThePageInUseWithTheFormHidden(): void
    {
        $scope = $this->contentScope([1]);

        $report = $this->usage(scopeFilter: $scope, forms: self::REDIRECT_FORMS)->forPage(12, 'thanks', [1]);

        // Form 22 is on store view 2 only; forms 21 (store view 1) and 23 (all store views) stay visible.
        $this->assertTrue($report['used']);
        $this->assertSame(2, $report['total']);
        $this->assertSame([21, 23], array_column(Cast::array($report['links']), 'id'));
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
    }

    public function testAPageUsedOnlyAsTheRedirectOfAFormTheCallerMayNotViewIsStillInUse(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturnCallback(
            static fn (string $feature): bool => $feature !== FeatureCheckerInterface::FORM_BUILDER
        );
        $features->method('isInstalledAndEnabled')->willReturn(true);
        $scope = $this->contentScope(null, [], null, [], null, $features);

        $report = $this->usage(scopeFilter: $scope, forms: [self::REDIRECT_FORMS[0]])->forPage(12, 'thanks', [1]);

        $this->assertTrue($report['used']);
        $this->assertSame(0, $report['total']);
        $this->assertSame([], $report['links']);
        $this->assertSame(1, $report['usages_hidden_by_permissions']);
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
     * @param array $forms Form nodes findFormNodes() walks, with the form id and store views.
     * @return DeletionUsage
     * @phpstan-param list<array{id: int, store_ids: list<int>, node: array<string, mixed>}> $forms
     */
    private function usage(
        bool $menuViewAllowed = true,
        ?array &$scanned = null,
        ?UsageFinder $usageFinder = null,
        ?ContentScopeFilter $scopeFilter = null,
        array $forms = []
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
        $scanner->method('findFormNodes')->willReturnCallback(static function (callable $predicate) use ($forms): array {
            $matches = [];
            foreach ($forms as $form) {
                if ($predicate($form['node'])) {
                    $matches[] = ['entity_type' => ContentScanner::TYPE_FORM, 'id' => $form['id'],
                        'store_ids' => $form['store_ids']];
                }
            }
            return $matches;
        });
        $menuService = $this->createMock(MenuService::class);
        $menuService->method('filterAssignments')->willReturnCallback(
            static fn (array $assignments): array => ['visible' => $assignments, 'hidden' => 0]
        );
        // An unrestricted caller: the real filter applies only the menu ACL.
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($menuViewAllowed);
        $features->method('isInstalledAndEnabled')->willReturn(true);
        return new DeletionUsage(
            $usageFinder ?? $this->createMock(UsageFinder::class),
            $scanner,
            $scopeFilter ?? $this->contentScope(null, [], null, [], null, $features),
            $menuService,
            $config,
            $storeManager
        );
    }
}
