<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Where a CMS page, CMS block or menu is still used, for the preview of the delete tools.
 *
 * `used` counts every usage; the lists and `total` hold only what the caller's website / store view restriction may
 * see, the rest is counted in usages_hidden_by_permissions (as the find-usage tools do), so content used only
 * elsewhere is never shown as unused. Menus are scanned even when the caller may not view them
 * (Hyva_MenuBuilder::menu_view); their usages count in `used`, and ContentScopeFilter moves them from the lists to
 * usages_hidden_by_permissions.
 */
class DeletionUsage
{
    /**
     * Store configuration that names a CMS page, path => what the store uses it for.
     */
    public const PAGE_CONFIG_PATHS = [
        'web/default/cms_home_page' => 'home page',
        'web/default/cms_no_route' => '404 (no route) page',
        'web/default/cms_no_cookies' => 'cookies disabled page',
    ];

    /**
     * @param UsageFinder $usageFinder
     * @param ContentScanner $contentScanner
     * @param ContentScopeFilter $scopeFilter
     * @param MenuService $menuService
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly UsageFinder $usageFinder,
        private readonly ContentScanner $contentScanner,
        private readonly ContentScopeFilter $scopeFilter,
        private readonly MenuService $menuService,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Usage of a CMS page: store configuration per store view and links to it in content and menus
     *
     * Store configuration: the home, 404 and cookies disabled page; links: Hyvä CMS content and menu items.
     *
     * @param int $id
     * @param string $identifier
     * @param array $storeIds Store views the page is assigned to.
     * @return array
     * @throws LocalizedException
     * @phpstan-param list<int> $storeIds
     * @phpstan-return array<string, mixed>
     */
    public function forPage(int $id, string $identifier, array $storeIds): array
    {
        $config = $this->pageConfig($id, $identifier, $storeIds);
        $refs = [(string) $id, $identifier];
        $links = $this->contentScanner->findNodes(static function (array $node) use ($refs): bool {
            $link = $node['link'] ?? null;
            return is_array($link)
                && ($link['type'] ?? null) === 'cms_page'
                && is_scalar($link['value'] ?? null)
                && in_array((string) $link['value'], $refs, true);
        }, ContentLocator::TYPES, true);
        $total = count($config) + count($links);
        $report = $this->scopeFilter->filterUsageReport(
            ['used' => $total > 0, 'total' => $total, 'store_config' => $config, 'links' => $links],
            ['links']
        );
        if ($this->scopeFilter->isRestricted()) {
            $filtered = $this->menuService->filterAssignments($config);
            $report['store_config'] = $filtered['visible'];
            $report['total'] = Cast::int($report['total']) - $filtered['hidden'];
            $report['usages_hidden_by_permissions'] = Cast::int($report['usages_hidden_by_permissions'] ?? 0)
                + $filtered['hidden'];
        }
        return $report;
    }

    /**
     * Usage of a CMS block: Hyvä CMS components, Magento CMS widget directives and widget instances
     *
     * @param int $id
     * @param string $identifier
     * @return array
     * @phpstan-return array<string, mixed>
     */
    public function forBlock(int $id, string $identifier): array
    {
        $report = $this->usageFinder->findBlockUsage($id, $identifier, true);
        return ['used' => $report['total'] > 0] + $this->scopeFilter->filterUsageReport(
            $report,
            ['hyva_content', 'magento_content'],
            ['widget_instances']
        );
    }

    /**
     * Usage of a menu: header/footer design configuration, content, widget instances, layout and templates
     *
     * The menu delete tool runs only for a caller allowed to view menus, so every menu is scanned here already.
     *
     * @param string $identifier
     * @return array
     * @phpstan-return array<string, mixed>
     */
    public function forMenu(string $identifier): array
    {
        return $this->menuService->withScopedAssignments($this->scopeFilter->filterUsageReport(
            $this->menuService->findUsage($identifier),
            ['hyva_cms_content', 'magento_cms_content'],
            ['widget_instances']
        ));
    }

    /**
     * Store views whose home, 404 or cookies page is this page (effective value, config.xml defaults included)
     *
     * A value "identifier|id" names one page; a bare identifier names the page with that identifier in the store
     * view, which is this page when it is assigned there or to all store views.
     *
     * @param int $id
     * @param string $identifier
     * @param array $storeIds
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return list<array{location: string, path: string, scope: string, scope_id: int}>
     */
    private function pageConfig(int $id, string $identifier, array $storeIds): array
    {
        $rows = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $assigned = in_array(0, $storeIds, true) || in_array($storeId, $storeIds, true);
            foreach (self::PAGE_CONFIG_PATHS as $path => $location) {
                $value = Cast::string($this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
                if ($value === $identifier . '|' . $id || ($assigned && $value === $identifier)) {
                    $rows[] = ['location' => $location, 'path' => $path, 'scope' => 'stores', 'scope_id' => $storeId];
                }
            }
        }
        return $rows;
    }
}
