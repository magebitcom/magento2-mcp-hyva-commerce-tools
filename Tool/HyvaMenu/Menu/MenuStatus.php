<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * `hyva_menu.status`: is Hyvä Menu Builder installed, enabled and usable, and how is it wired.
 */
class MenuStatus extends AbstractTool
{
    public const TOOL_NAME = 'hyva_menu.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_status';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::cms';

    public const CONFIG_HEADER_IDENTIFIER = 'design/header/topmenu_identifier';
    public const CONFIG_HEADER_LOCATION = 'design/header/topmenu_location';
    public const CONFIG_FOOTER_IDENTIFIER = 'design/footer/footer_menu_identifier';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuGatewayInterface $menuGateway
     * @param ComponentProvider $componentProvider
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly ComponentProvider $componentProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Menu Builder Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check whether Hyvä Menu Builder is installed, enabled and allowed for this token. Returns the '
            . 'version, menu components, rendering methods, header/footer menu assignment per store view and menu '
            . 'counts. Call before any other hyva_menu.* tool.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $status = $this->featureChecker->getStatus(FeatureCheckerInterface::MENU_BUILDER);
        $payload = ['status' => $status];

        if ($status['installed'] && $status['enabled']) {
            $components = [];
            foreach ($this->componentProvider->getComponents() as $name => $definition) {
                $template = is_array($definition)
                    && is_string($definition['template'] ?? null) ? $definition['template'] : '';
                if (str_starts_with($template, 'Hyva_MenuBuilder::')) {
                    $components[] = [
                        'name' => (string) $name,
                        'label' => is_string($definition['label'] ?? null) ? $definition['label'] : (string) $name,
                        'menu_root' => in_array('hyva_menu_root', (array) ($definition['context_flags'] ?? []), true),
                    ];
                }
            }

            $stores = [];
            foreach ($this->storeManager->getStores() as $store) {
                $storeId = (int) $store->getId();
                $stores[] = [
                    'store_id' => $storeId,
                    'code' => $store->getCode(),
                    'name' => $store->getName(),
                    'header_menu' => $this->configValue(self::CONFIG_HEADER_IDENTIFIER, $storeId),
                    'header_location' => $this->configValue(self::CONFIG_HEADER_LOCATION, $storeId) ?? 'topmenu',
                    'footer_menu' => $this->configValue(self::CONFIG_FOOTER_IDENTIFIER, $storeId),
                ];
            }

            $menus = $this->menuGateway->getList();
            $payload += [
                'components' => $components,
                'rendering_methods' => [
                    'header' => 'Design config '
                        . self::CONFIG_HEADER_IDENTIFIER
                        . ' (location '
                        . self::CONFIG_HEADER_LOCATION
                        . ')',
                    'footer' => 'Design config ' . self::CONFIG_FOOTER_IDENTIFIER,
                    'cms_component' => 'hyva_menu_widget component inside Hyvä CMS pages/blocks',
                    'magento_widget' => 'Widget hyva_cms_menu (Hyva\\MenuBuilder\\Block\\Widget\\Menu)',
                ],
                'store_views' => $stores,
                'menus' => [
                    'total' => count($menus),
                    'active' => count(array_filter($menus, static fn (array $menu): bool => (bool) $menu['is_active'])),
                    'unassigned' => count(
                        array_filter($menus, static fn (array $menu): bool => $menu['store_ids'] === [])
                    ),
                ],
            ];
        }

        return $this->jsonResult($payload, ['available' => $status['available']]);
    }

    /**
     * Config value.
     *
     * @param string $path
     * @param int $storeId
     * @return string|null
     */
    private function configValue(string $path, int $storeId): ?string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
