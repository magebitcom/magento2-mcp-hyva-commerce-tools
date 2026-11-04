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
use Magebit\McpHyvaCommerceTools\Model\Media\ImageEngineInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * magebit_media_health_widget: media problems and Media Optimization state. The MCP activity section and the audit
 * log link need Magebit_Mcp::mcp_audit.
 */
class MediaHealth extends AbstractHealthWidget
{
    /**
     * @param MediaAuditor $mediaAuditor
     * @param MediaService $mediaService
     * @param ImageEngineInfo $engineInfo
     * @param McpActivityReader $activityReader
     */
    public function __construct(
        private readonly MediaAuditor $mediaAuditor,
        private readonly MediaService $mediaService,
        private readonly ImageEngineInfo $engineInfo,
        private readonly McpActivityReader $activityReader
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function widgetProperties(): array
    {
        return [
            'row_count' => $this->numberProperty('Images to list', 6, 50),
            'max_kb' => $this->numberProperty('Oversize threshold (KB)', 500, 10000),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        $report = $this->mediaAuditor->audit(
            'all',
            $this->intProperty($widgetInstance, 'max_kb', 500),
            $this->intProperty($widgetInstance, 'row_count', 6)
        );
        $counts = (array) $report['counts'];
        $settings = $this->mediaService->getSettings();
        $engines = $this->engineInfo->getEngines();
        $rows = [];
        foreach ($report['images'] as $image) {
            $codes = array_column(Cast::array($image['issues'] ?? []), 'code');
            $rows[] = $this->row(
                Cast::string($image['path']),
                Cast::string($image['size_kb']) . ' KB · ' . count(Cast::array($image['used_in'] ?? [])) . ' usage(s)',
                implode(', ', array_slice($codes, 0, 3)),
                in_array('broken_reference', $codes, true) ? 'danger' : 'warning'
            );
        }
        $sections = [
            [
                'title' => (string) __('Images needing attention'),
                'rows' => $rows,
                'empty' => (string) __('No media problems found.'),
            ],
        ];
        $links = [];
        if ($this->activityReader->isAllowed()) {
            $recent = [];
            foreach ($this->activityReader->getRows(['tool_prefix' => 'hyva_media.'], 5) as $call) {
                $recent[] = $this->row(
                    $call['tool'],
                    $call['admin'] . ' · ' . $call['created_at'],
                    $call['status'],
                    $call['status'] === 'ok' ? 'success' : 'danger'
                );
            }
            $sections[] = [
                'title' => (string) __('Last MCP media actions'),
                'rows' => $recent,
                'empty' => (string) __('None.'),
            ];
            $links[] = $this->link((string) __('MCP audit log'), 'magebit_mcp/auditlog/index');
        }
        $missingVariants = ($counts['not_optimized'] ?? 0)
            + ($counts['missing_webp'] ?? 0)
            + ($counts['missing_avif'] ?? 0);
        return [
            'stats' => [
                $this->stat(
                    'Oversized',
                    ($counts['oversized_file'] ?? 0) + ($counts['oversized_dimensions'] ?? 0),
                    $this->level(($counts['oversized_file'] ?? 0) + ($counts['oversized_dimensions'] ?? 0))
                ),
                $this->stat(
                    'Missing alt (all image usages)',
                    $counts['missing_alt'] ?? 0,
                    $this->level($counts['missing_alt'] ?? 0)
                ),
                $this->stat(
                    'Broken references',
                    $counts['broken_reference'] ?? 0,
                    $this->level($counts['broken_reference'] ?? 0, 'danger')
                ),
                $this->stat('Without WebP/AVIF', $missingVariants, $this->level($missingVariants)),
                $this->stat(
                    'Optimization',
                    $settings === null ? 'n/a' : (empty($settings['enabled']) ? 'off' : 'on'),
                    $settings !== null && !empty($settings['enabled']) ? 'success' : 'warning'
                ),
                $this->stat(
                    'Auto replacement',
                    $settings === null ? 'n/a' : (empty($settings['automatic_replacement']) ? 'off' : 'on'),
                    $settings !== null && !empty($settings['automatic_replacement']) ? 'success' : 'warning'
                ),
                $this->stat(
                    'GD / Imagick',
                    ($engines['gd']['available'] ? 'yes' : 'no') . ' / ' . (
                        $engines['imagick']['available'] ? 'yes' : 'no'
                    )
                ),
            ],
            'sections' => $sections,
            'notice' => $this->notice($report),
            'links' => $links,
        ];
    }

    /**
     * Notice under the stats; a website-restricted admin is told the files are shared and which usages were checked
     *
     * @param array $report MediaAuditor::audit() report
     * @return string
     * @phpstan-param array{images_checked: int, usages_hidden_by_permissions?: int} $report
     */
    private function notice(array $report): string
    {
        $notice = (string) __('%1 referenced images checked.', $report['images_checked']);
        if (!array_key_exists('usages_hidden_by_permissions', $report)) {
            return $notice;
        }
        return $notice . ' ' . (string) __(
            'Media files are shared by all websites (the Hyvä Media Gallery has no website or store view scope), '
            . 'so the files are the same for every admin; only their usages in your websites and store views are '
            . 'checked (%1 usages elsewhere left out).',
            $report['usages_hidden_by_permissions']
        );
    }
}
