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
use Magebit\McpHyvaCommerceTools\Model\Audit\CmsAuditor;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Backend\Model\UrlInterface;

/**
 * magebit_content_health_widget: Hyvä CMS content problems found by the CMS audit.
 */
class ContentHealth extends AbstractHealthWidget
{
    /**
     * @param CmsAuditor $cmsAuditor
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly CmsAuditor $cmsAuditor,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function widgetProperties(): array
    {
        return [
            'row_count' => $this->numberProperty('Issues to list', 8, 50),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        $report = $this->cmsAuditor->audit($this->intProperty($widgetInstance, 'row_count', 8));
        $pages = $report['counts']['cms_page'] ?? [];
        $blocks = $report['counts']['cms_block'] ?? [];
        $issues = $report['content_issues'];
        $unpublished = 0;
        foreach ([$pages, $blocks] as $counts) {
            $unpublished += ($counts['status'][ContentIndex::STATUS_PUBLISHED_WITH_DRAFT] ?? 0)
                + ($counts['status'][ContentIndex::STATUS_DRAFT_ONLY] ?? 0);
        }
        $legacy = $pages['legacy']['total'] ?? 0;
        $rows = [];
        foreach ($report['issues'] as $issue) {
            $where = null;
            if (isset($issue['entity_type'])) {
                $where = Cast::string($issue['entity_type']) . ' ' . Cast::string($issue['identifier'] ?? '')
                    . ' (' . Cast::string($issue['edition'] ?? '') . ')';
            }
            $url = isset($issue['entity_type'], $issue['id']) ? $this->url->getUrl(
                'liveview/editor/index',
                ['type' => $issue['entity_type'], 'id' => $issue['id']]
            ) : null;
            $rows[] = $this->row(
                Cast::string($issue['message'] ?? ''),
                $where,
                Cast::string($issue['code'] ?? ''),
                $this->badgeSeverity($issue['severity'] ?? ''),
                $url
            );
        }
        return [
            'stats' => [
                $this->stat('Missing alt text', $issues['missing_alt'], $this->level($issues['missing_alt'])),
                $this->stat(
                    'Broken images',
                    $issues['broken_images'],
                    $this->level($issues['broken_images'], 'danger')
                ),
                $this->stat(
                    'Invalid components',
                    $issues['validation_errors'] + $issues['invalid_json'],
                    $this->level($issues['validation_errors'] + $issues['invalid_json'], 'danger')
                ),
                $this->stat(
                    'Disabled components',
                    $issues['disabled_components'],
                    $this->level($issues['disabled_components'])
                ),
                $this->stat('Unpublished drafts', $unpublished, $this->level($unpublished)),
                $this->stat(
                    'Schedule problems',
                    $report['scheduling']['conflict_errors'] + $report['scheduling']['overdue_releases'],
                    $this->level(
                        $report['scheduling']['conflict_errors'] + $report['scheduling']['overdue_releases'],
                        'danger'
                    )
                ),
                $this->stat('Pages still on Magento content', $legacy, $this->level($legacy)),
            ],
            'sections' => [
                [
                    'title' => (string) __('Top issues'),
                    'rows' => $rows,
                    'empty' => (string) __('No content problems found.'),
                ]
            ],
            'notice' => (string) __('Translation coverage per store view is not checked yet.'),
            'links' => [['label' => (string) __('Hyvä CMS pages'), 'url' => $this->url->getUrl('cms/page/index')]],
        ];
    }
}
