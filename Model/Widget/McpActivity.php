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
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Backend\Model\UrlInterface;

/**
 * magebit_mcp_activity_widget: recent MCP tool calls, failures, AI drafts and destructive actions.
 */
class McpActivity extends AbstractHealthWidget
{
    /**
     * @param McpActivityReader $activityReader
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly McpActivityReader $activityReader,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * The widget is the core's audit log: it also needs Magebit_Mcp::mcp_audit, not only the widget resource.
     *
     * @param WidgetContextInterface $ctx
     * @param WidgetInstanceInterface|null $widgetInstance
     * @return bool
     */
    public function isAllowed(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): bool
    {
        return parent::isAllowed($ctx, $widgetInstance) && $this->activityReader->isAllowed();
    }

    /**
     * @inheritDoc
     */
    protected function widgetProperties(): array
    {
        return [
            'row_count' => $this->numberProperty('Rows per list', 8, 30),
            'period_hours' => $this->numberProperty('Statistics period (hours)', 24, 720),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        $limit = $this->intProperty($widgetInstance, 'row_count', 8);
        $hours = $this->intProperty($widgetInstance, 'period_hours', 24);
        $totals = $this->activityReader->getTotals(gmdate('Y-m-d H:i:s', time() - $hours * 3600));
        $toRows = function (array $rows): array {
            $result = [];
            foreach ($rows as $row) {
                $target = isset($row['summary']['entity_type'], $row['summary']['id'])
                    ? Cast::string($row['summary']['entity_type']) . ' #' . Cast::string($row['summary']['id'])
                    : null;
                $result[] = $this->row(
                    $row['tool'],
                    trim($row['admin'] . ' · ' . $row['created_at'] . ($target !== null ? ' · ' . $target : '')),
                    $row['status'] === 'ok' ? 'ok' : 'error ' . ($row['error_code'] ?? ''),
                    $row['status'] === 'ok' ? 'success' : 'danger'
                );
            }
            return $result;
        };
        return [
            'stats' => [
                $this->stat(sprintf('Calls (%dh)', $hours), $totals['calls']),
                $this->stat('Failed', $totals['errors'], $this->level($totals['errors'], 'danger')),
                $this->stat('Writes', $totals['writes']),
                $this->stat('Admins', $totals['admins']),
            ],
            'sections' => [
                [
                    'title' => (string) __('Last MCP actions'),
                    'rows' => $toRows($this->activityReader->getRows([], $limit)),
                    'empty' => (string) __('No MCP calls yet.'),
                ],
                [
                    'title' => (string) __('AI-generated drafts'),
                    'rows' => $toRows(
                        $this->activityReader->getRows(
                            ['tools' => $this->activityReader->getDraftWriteTools(), 'status' => 'ok'],
                            $limit
                        )
                    ),
                    'empty' => (string) __('No drafts written over MCP.'),
                ],
                [
                    'title' => (string) __('Failed calls'),
                    'rows' => $toRows($this->activityReader->getRows(['status' => 'error'], $limit)),
                    'empty' => (string) __('No failed calls.'),
                ],
                [
                    'title' => (string) __('Publish and destructive actions'),
                    'rows' => $toRows(
                        $this->activityReader->getRows(
                            ['tools' => $this->activityReader->getDestructiveTools()],
                            $limit
                        )
                    ),
                    'empty' => (string) __('None.'),
                ],
            ],
            'notice' => (string) __(
                'Approval workflows (hyva_mcp.approval.*) are not part of this release; every write still needs the '
                    . 'MCP write gate and client confirmation.'
            ),
            'links' => [
                [
                    'label' => (string) __('Open MCP audit log'),
                    'url' => $this->url->getUrl('magebit_mcp/auditlog/index'),
                ]
            ],
        ];
    }
}
