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
use Magento\Backend\Model\UrlInterface;

/**
 * magebit_media_health_widget: media problems and Media Optimization state.
 */
class MediaHealth extends AbstractHealthWidget
{
    /**
     * @param MediaAuditor $mediaAuditor
     * @param MediaService $mediaService
     * @param ImageEngineInfo $engineInfo
     * @param McpActivityReader $activityReader
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly MediaAuditor $mediaAuditor,
        private readonly MediaService $mediaService,
        private readonly ImageEngineInfo $engineInfo,
        private readonly McpActivityReader $activityReader,
        private readonly UrlInterface $url
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
        $counts = $report['counts'];
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
        $recent = [];
        foreach ($this->activityReader->getRows(['tool_prefix' => 'hyva_media.'], 5) as $call) {
            $recent[] = $this->row(
                $call['tool'],
                $call['admin'] . ' · ' . $call['created_at'],
                $call['status'],
                $call['status'] === 'ok' ? 'success' : 'danger'
            );
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
                $this->stat('Missing alt', $counts['missing_alt'] ?? 0, $this->level($counts['missing_alt'] ?? 0)),
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
            'sections' => [
                [
                    'title' => (string) __('Images needing attention'),
                    'rows' => $rows,
                    'empty' => (string) __('No media problems found.'),
                ],
                ['title' => (string) __('Last MCP media actions'), 'rows' => $recent, 'empty' => (string) __('None.')],
            ],
            'notice' => (string) __('%1 referenced images checked.', $report['images_checked']),
            'links' => [
                ['label' => (string) __('MCP audit log'), 'url' => $this->url->getUrl('magebit_mcp/auditlog/index')]
            ],
        ];
    }
}
