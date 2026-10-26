<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit;

use Composer\InstalledVersions;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Audit\ModuleLenses;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * `hyva_commerce.audit.overview`: installed Hyvä Commerce modules, versions, feature flags and tool availability.
 */
class AuditOverview extends AbstractTool
{
    public const TOOL_NAME = 'hyva_commerce.audit.overview';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_commerce_audit_overview';
    public const UNDERLYING_ACL_RESOURCE = null;

    private const CONFIG_FLAGS = [
        'hyva_cms_enabled' => 'hyva_cms/general/enabled',
        'hyva_cms_pages_enabled' => 'hyva_cms/magento_cms/cms_page_enabled',
        'hyva_cms_blocks_enabled' => 'hyva_cms/magento_cms/cms_block_enabled',
        'hyva_cms_scheduling_enabled' => 'hyva_cms/scheduling/enabled',
        'media_optimization_enabled' => 'hyva_commerce_image_optimization/optimization/optimize',
        'admin_dashboard_enabled' => 'hyva_admin_dashboard/general/enable',
        'mcp_allow_writes' => 'magebit_mcp/general/allow_writes',
    ];

    private const TOOL_PREFIXES = [
        FeatureCheckerInterface::CMS => ['hyva_cms.', 'hyva_commerce.audit.cms'],
        FeatureCheckerInterface::MENU_BUILDER => ['hyva_menu.', 'hyva_commerce.audit.menu'],
        FeatureCheckerInterface::MEDIA_OPTIMIZATION => ['hyva_media.optimization.'],
        FeatureCheckerInterface::ADMIN_DASHBOARD => ['hyva_dashboard.'],
        FeatureCheckerInterface::MEDIA_GALLERY => ['hyva_media.asset.', 'hyva_media.trash.', 'hyva_media.folder.',
            'hyva_media.sync.', 'hyva_media.audit.literal_urls'],
        FeatureCheckerInterface::FORM_BUILDER => ['hyva_form.'],
        FeatureCheckerInterface::EMAIL_TEMPLATES => ['hyva_email.'],
        FeatureCheckerInterface::STORE_CREDIT => ['hyva_store_credit.'],
        FeatureCheckerInterface::LINKED_PRODUCTS => ['hyva_linked.'],
        FeatureCheckerInterface::CMS_AI_TRANSLATIONS => ['hyva_cms.translation.'],
    ];

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param ModuleManager $moduleManager
     * @param ScopeConfigInterface $scopeConfig
     * @param ToolRegistryInterface $toolRegistry
     * @param ModuleLenses $moduleLenses
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        private readonly ModuleManager $moduleManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly ModuleLenses $moduleLenses
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Audit Hyvä Commerce Installation';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Report installed Hyvä Commerce modules with versions and enabled state, the Hyvä CMS / '
            . 'Menu Builder / '
            . 'Media Optimization / Image Editor / Admin Dashboard feature status, key config flags, and which '
            . 'hyva_* MCP tools are usable. With lenses (default): one-line health of forms (delivery errors), '
            . 'transactional emails (Hyvä designs live), CMS images without a Media Gallery asset id, store credit '
            . 'and linked product groups, each naming the tool with the details. Call this first to learn what the '
            . 'store supports.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->boolean('lenses', fn (BooleanBuilder $b) => $b->description('Include the module health lenses. '
                . 'Default true.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $modules = [];
        foreach (array_keys($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE)) as $moduleName) {
            if (str_starts_with($moduleName, 'Hyva_')) {
                $modules[$moduleName] = $this->moduleManager->isEnabled($moduleName);
            }
        }
        ksort($modules);

        $packages = [];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if (str_starts_with($package, 'hyva-themes/commerce-') || $package === 'magebitcom/magento2-mcp-module') {
                $packages[$package] = InstalledVersions::getPrettyVersion($package);
            }
        }
        ksort($packages);

        $config = [];
        foreach (self::CONFIG_FLAGS as $key => $path) {
            $config[$key] = $this->scopeConfig->isSetFlag($path);
        }

        $features = $this->featureChecker->getAll();
        $tools = [];
        foreach ($this->toolRegistry->all() as $tool) {
            $name = $tool->getName();
            foreach (self::TOOL_PREFIXES as $feature => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $available = (bool) ($features[$feature]['available'] ?? false);
                        $tools[$feature]['available'] = $available;
                        $tools[$feature]['tools'][] = $name;
                    }
                }
            }
        }

        $disabled = array_keys(array_filter($modules, static fn (bool $enabled): bool => !$enabled));
        $payload = [
            'features' => $features,
            'config' => $config,
            'modules' => $modules,
            'disabled_modules' => $disabled,
            'packages' => $packages,
            'mcp_tools' => $tools,
        ];
        if ($this->boolArg($arguments, 'lenses', true)) {
            // Null lens: module unavailable, or your role may not read that data.
            $payload['lenses'] = $this->moduleLenses->collect();
        }

        return $this->jsonResult($payload, [
            'hyva_modules' => count($modules),
            'disabled_modules' => count($disabled),
        ]);
    }
}
