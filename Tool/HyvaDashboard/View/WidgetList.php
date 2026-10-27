<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaDashboard\View;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\DashboardCatalog;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

class WidgetList extends AbstractTool
{
    public const TOOL_NAME = 'hyva_dashboard.widget.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_dashboard_widget_list';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AdminDashboardFramework::dashboard';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param DashboardCatalog $catalog
     * @param CallerContext $callerContext
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly DashboardCatalog $catalog,
        private readonly CallerContext $callerContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä Dashboard Widgets';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Hyvä Admin Dashboard widget types your role may add (title, category, ACL), and with view_id '
            . 'the widgets placed on that view (only views you can see; widgets your role may not use are left out). '
            . 'Read only.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('view_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::ADMIN_DASHBOARD)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::ADMIN_DASHBOARD)
            );
        }
        $adminId = $this->callerContext->adminUserId();
        if ($adminId === null) {
            return $this->softError('This MCP token has no admin user.');
        }
        try {
            $result = $this->catalog->widgets($adminId, $this->intArg($arguments, 'view_id'));
        } catch (LocalizedException $e) {
            return $this->softError($e->getMessage());
        }
        return $this->jsonResult($result, ['types' => count($result['types']), 'placed' => count($result['placed'])]);
    }
}
