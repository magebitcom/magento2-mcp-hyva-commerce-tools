<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Widget;

use Hyva\AdminDashboardApi\Api\V1\WidgetContextInterface;
use Hyva\AdminDashboardApi\Api\V1\WidgetInstanceInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Backend\Model\UrlInterface;

/**
 * magebit_menu_health_widget: Hyvä Menu Builder health from the menu validator, over the menus of the admin's
 * websites/store views. The MCP activity section and the audit log link need Magebit_Mcp::mcp_audit.
 */
class MenuHealth extends AbstractHealthWidget
{
    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuGatewayInterface $menuGateway
     * @param MenuService $menuService
     * @param McpActivityReader $activityReader
     * @param UrlInterface $url
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly MenuService $menuService,
        private readonly McpActivityReader $activityReader,
        private readonly UrlInterface $url,
        private readonly ContentScopeFilter $scopeFilter
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER)) {
            $unavailable = $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::MENU_BUILDER);
            return [
                'stats' => [],
                'sections' => [],
                'notice' => $unavailable['reason'] . ' ' . $unavailable['hint'],
                'links' => [],
            ];
        }
        $codes = [];
        $rows = [];
        $menus = $this->scopeFilter->filterByStoreIds($this->menuGateway->getList())['visible'];
        foreach ($menus as $menu) {
            $result = $this->menuService->validate(
                $menu,
                Cast::bool($menu['is_published'] ?? false) ? 'published' : 'draft'
            );
            foreach ($result['issues'] as $issue) {
                $code = Cast::string($issue['code']);
                $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
            $placements = array_column(
                $this->menuService->getAssignments(Cast::string($menu['identifier'])),
                'location'
            );
            $rows[] = $this->row(
                Cast::string($menu['title']) . ' (' . Cast::string($menu['identifier']) . ')',
                ($placements !== [] ? implode(', ', $placements) : (string) __('not placed')) . ' · ' . (
                    Cast::bool($menu['is_published'] ?? false) ? __('published') : __('draft only')
                ),
                $result['counts']['error'] . ' errors / ' . $result['counts']['warning'] . ' warnings',
                $result['counts']['error'] > 0 ? 'danger' : ($result['counts']['warning'] > 0 ? 'warning' : 'success'),
                $this->url->getUrl('liveview/editor/index', ['type' => 'menu', 'id' => $menu['id']])
            );
        }
        $broken = ($codes['broken_category'] ?? 0)
            + ($codes['missing_product'] ?? 0)
            + ($codes['missing_cms_page'] ?? 0)
            + ($codes['broken_url'] ?? 0);
        $disabled = ($codes['inactive_category'] ?? 0)
            + ($codes['disabled_product'] ?? 0)
            + ($codes['inactive_cms_page'] ?? 0);
        $sections = [['title' => (string) __('Menus'), 'rows' => $rows, 'empty' => (string) __('No menus yet.')]];
        $links = [
            ['label' => (string) __('Menu Builder'), 'url' => $this->url->getUrl('hyva_menu_builder/menu/index')],
        ];
        if ($this->activityReader->isAllowed()) {
            $recent = [];
            foreach ($this->activityReader->getRows(['tool_prefix' => 'hyva_menu.'], 5) as $call) {
                $recent[] = $this->row(
                    $call['tool'],
                    $call['admin'] . ' · ' . $call['created_at'],
                    $call['status'],
                    $call['status'] === 'ok' ? 'success' : 'danger'
                );
            }
            $sections[] = [
                'title' => (string) __('Last MCP menu actions'),
                'rows' => $recent,
                'empty' => (string) __('None.'),
            ];
            $links[] = [
                'label' => (string) __('MCP audit log'),
                'url' => $this->url->getUrl('magebit_mcp/auditlog/index'),
            ];
        }
        return [
            'stats' => [
                $this->stat('Menus', count($menus)),
                $this->stat('Broken links', $broken, $this->level($broken, 'danger')),
                $this->stat('Disabled targets', $disabled, $this->level($disabled)),
                $this->stat('Empty columns', $codes['empty_column'] ?? 0, $this->level($codes['empty_column'] ?? 0)),
                $this->stat(
                    'Unassigned menus',
                    $codes['unassigned_menu'] ?? 0,
                    $this->level($codes['unassigned_menu'] ?? 0)
                ),
            ],
            'sections' => $sections,
            'notice' => null,
            'links' => $links,
        ];
    }
}
