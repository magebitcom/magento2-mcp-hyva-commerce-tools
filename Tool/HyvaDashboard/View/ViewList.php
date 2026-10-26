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
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\DashboardCatalog;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

class ViewList extends AbstractTool
{
    public const TOOL_NAME = 'hyva_dashboard.view.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_dashboard_view_list';
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
        return 'List Hyvä Dashboard Views';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List the Hyvä Admin Dashboard views (boards) you own or that are shared with you (with everyone or '
            . 'your roles), their sharing, and which one opens by default for you. Read only.';
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
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::ADMIN_DASHBOARD)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::ADMIN_DASHBOARD)
            );
        }
        $adminId = $this->callerContext->adminUserId();
        if ($adminId === null) {
            return $this->softError('This MCP token has no admin user.');
        }
        $result = $this->catalog->views($adminId);
        return $this->jsonResult($result, ['views' => count($result['views'])]);
    }
}
