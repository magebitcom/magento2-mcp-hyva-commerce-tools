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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * magebit_campaign_calendar_widget: upcoming and recent Hyvä CMS scheduled releases with conflicts, for the content
 * of the admin's websites/store views.
 */
class CampaignCalendar extends AbstractHealthWidget
{
    /**
     * @param ScheduleService $scheduleService
     * @param ConflictReport $conflictReport
     * @param ScheduleTime $scheduleTime
     * @param ScopeConfigInterface $scopeConfig
     * @param ContentIndex $contentIndex
     * @param UrlInterface $url
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ConflictReport $conflictReport,
        private readonly ScheduleTime $scheduleTime,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ContentIndex $contentIndex,
        private readonly UrlInterface $url,
        private readonly ContentScopeFilter $scopeFilter
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function widgetProperties(): array
    {
        return [
            'days_ahead' => $this->numberProperty('Days ahead', 30, 365),
            'days_back' => $this->numberProperty('Days back', 7, 90),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        $ahead = $this->intProperty($widgetInstance, 'days_ahead', 30);
        $back = $this->intProperty($widgetInstance, 'days_back', 7);
        $homeIdentifier = Cast::string($this->scopeConfig->getValue('web/default/cms_home_page'), 'home');
        $homeIds = [];
        foreach ($this->contentIndex->getRows('cms_page') as $page) {
            if ($page['identifier'] === strtok($homeIdentifier, '|')) {
                $homeIds[] = $page['id'];
            }
        }
        $now = time();
        $items = $this->scopeFilter->filter(
            $this->scheduleService->getItems(
                [
                    'status' => 'any',
                    'from' => gmdate('Y-m-d H:i:s', $now - $back * 86400),
                    'to' => gmdate('Y-m-d H:i:s', $now + $ahead * 86400),
                ]
            ),
            'entity_type',
            'entity_id'
        )['visible'];
        $upcoming = [];
        $recent = [];
        $homepage = 0;
        $menus = 0;
        foreach ($items as $item) {
            $isHome = $item['entity_type'] === 'cms_page' && in_array($item['entity_id'], $homeIds, true);
            $homepage += $isHome && $item['status'] === 'pending' ? 1 : 0;
            $menus += $item['entity_type'] === 'menu' && $item['status'] === 'pending' ? 1 : 0;
            $label = $item['release_name'] . ': ' . $item['name'] . ($isHome ? ' (homepage)' : '');
            $detail = $this->scheduleTime->toLocal($item['time_scheduled'])
                . ' · '
                . $item['entity_type']
                . ' #'
                . $item['entity_id'];
            $severity = match ($item['status']) {
                'pending' => 'warning',
                'success' => 'success',
                default => 'danger',
            };
            $row = $this->row(
                $label,
                $detail,
                $item['status'],
                $severity,
                $this->url->getUrl(
                    'liveview/editor/index',
                    ['type' => $item['entity_type'], 'id' => $item['entity_id']]
                )
            );
            if ($item['status'] === 'pending' && strtotime($item['time_scheduled'] . ' UTC') >= $now - 900) {
                $upcoming[] = $row;
            } else {
                $recent[] = $row;
            }
        }
        $conflictRows = [];
        $errors = 0;
        foreach ($this->conflictReport->getConflictsWithinCallerScope()['conflicts'] as $conflict) {
            if ($conflict['severity'] === 'info') {
                continue;
            }
            $errors += $conflict['severity'] === 'error' ? 1 : 0;
            $conflictRows[] = $this->row(
                $conflict['message'],
                null,
                $conflict['code'],
                $this->badgeSeverity($conflict['severity'])
            );
        }
        return [
            'stats' => [
                $this->stat('Upcoming', count($upcoming)),
                $this->stat('Homepage changes', $homepage),
                $this->stat('Menu changes', $menus),
                $this->stat('Conflicts', count($conflictRows), $this->level($errors, 'danger')),
            ],
            'sections' => [
                [
                    'title' => (string) __('Upcoming (next %1 days)', $ahead),
                    'rows' => $upcoming,
                    'empty' => (string) __('Nothing scheduled.'),
                ],
                ['title' => (string) __('Conflicts'), 'rows' => $conflictRows, 'empty' => (string) __('No conflicts.')],
                [
                    'title' => (string) __('Published or expired (last %1 days)', $back),
                    'rows' => $recent,
                    'empty' => (string) __('None.'),
                ],
            ],
            'notice' => (string) __(
                'Times in %1. Hyvä releases have no end date; schedule a later release to end a campaign.',
                $this->scheduleTime->getStoreTimezone()
            ),
            'links' => [],
        ];
    }
}
