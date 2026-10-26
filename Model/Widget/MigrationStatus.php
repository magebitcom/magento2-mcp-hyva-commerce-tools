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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Backend\Model\UrlInterface;

/**
 * magebit_migration_status_widget: Page Builder/WYSIWYG to Hyvä CMS migration progress.
 */
class MigrationStatus extends AbstractHealthWidget
{
    /**
     * @param MigrationService $migrationService
     * @param ContentIndex $contentIndex
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly ContentIndex $contentIndex,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function widgetProperties(): array
    {
        return [
            'row_count' => $this->numberProperty('Entities to list', 8, 50),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        $pages = $this->contentIndex->getRows('cms_page');
        $hyva = count(array_filter($pages, static fn (array $p): bool => $p['liveview_enabled']));
        $legacy = $this->migrationService->scan();
        $complexity = ['low' => 0, 'medium' => 0, 'high' => 0];
        $kinds = ['page_builder' => 0, 'wysiwyg' => 0];
        $waiting = 0;
        $rows = [];
        foreach ($legacy as $item) {
            $complexity[$item['complexity']] = ($complexity[$item['complexity']] ?? 0) + 1;
            $kinds[$item['kind']] = ($kinds[$item['kind']] ?? 0) + 1;
            $waiting += $item['converted_draft_waiting'] ? 1 : 0;
        }
        foreach (array_slice($legacy, 0, $this->intProperty($widgetInstance, 'row_count', 8)) as $item) {
            $rows[] = $this->row(
                $item['title'] . ' (' . $item['identifier'] . ')',
                $item['entity_type'] . ' · ' . $item['kind'] . ' · ' . $item['element_count'] . ' elements',
                $item['converted_draft_waiting'] ? 'draft ready' : Cast::string($item['complexity']),
                $item['converted_draft_waiting'] ? 'success' : ($item['complexity'] === 'high' ? 'danger' : 'warning'),
                $this->url->getUrl('liveview/editor/index', ['type' => $item['entity_type'], 'id' => $item['id']])
            );
        }
        $total = count($pages);
        return [
            'stats' => [
                $this->stat('CMS pages', $total),
                $this->stat(
                    'Hyvä CMS pages',
                    $hyva . ($total > 0 ? ' (' . (int) round($hyva * 100 / $total) . '%)' : ''),
                    $hyva === $total ? 'success' : null
                ),
                $this->stat('Page Builder', $kinds['page_builder'] ?? 0, $this->level($kinds['page_builder'] ?? 0)),
                $this->stat('WYSIWYG', $kinds['wysiwyg'] ?? 0, $this->level($kinds['wysiwyg'] ?? 0)),
                $this->stat(
                    'Complexity L / M / H',
                    $complexity['low'] . ' / ' . $complexity['medium'] . ' / ' . $complexity['high']
                ),
                $this->stat('Converted drafts waiting', $waiting, $waiting > 0 ? 'warning' : 'success'),
            ],
            'sections' => [
                [
                    'title' => (string) __('Legacy content (most complex first)'),
                    'rows' => $rows,
                    'empty' => (string) __('Everything renders with Hyvä CMS.'),
                ]
            ],
            'notice' => (string) __(
                'Counts include pages and blocks. Convert with hyva_cms.migration.convert_to_draft.'
            ),
            'links' => [],
        ];
    }
}
