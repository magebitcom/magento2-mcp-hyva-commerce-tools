<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Menu;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

/**
 * Base for hyva_menu.* tools: answers with a soft "feature not available" result when Hyvä Menu Builder is
 * missing, disabled or not allowed, before any Menu Builder code is touched.
 */
abstract class AbstractMenuTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_view';
    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly MenuService $menuService
    ) {
    }

    /**
     * Hyvä resource the tool is listed with.
     *
     * Menu Builder's resources only exist while the module is enabled; without it the tool stays listed under
     * Hyvä CMS access and answers that the feature is unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::MENU_BUILDER)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Hyva_Cms::cms';
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::MENU_BUILDER)
            );
        }
        return $this->executeMenu($arguments);
    }

    /**
     * Execute menu.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeMenu(array $arguments): ToolResultInterface;
}
