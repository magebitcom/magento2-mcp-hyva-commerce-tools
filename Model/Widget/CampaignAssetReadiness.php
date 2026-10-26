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
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageChecker;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuValidator;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * magebit_campaign_asset_readiness_widget: are scheduled campaign pages, blocks and menus media- and link-ready.
 */
class CampaignAssetReadiness extends AbstractHealthWidget
{
    /**
     * @param ScheduleService $scheduleService
     * @param ComponentTree $componentTree
     * @param ImageReferenceExtractor $extractor
     * @param ImageChecker $imageChecker
     * @param MediaService $mediaService
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuValidator $menuValidator
     * @param ResourceConnection $resourceConnection
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ComponentTree $componentTree,
        private readonly ImageReferenceExtractor $extractor,
        private readonly ImageChecker $imageChecker,
        private readonly MediaService $mediaService,
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly MenuValidator $menuValidator,
        private readonly ResourceConnection $resourceConnection,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function widgetProperties(): array
    {
        return [
            'max_kb' => $this->numberProperty('Large image threshold (KB)', 300, 10000),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array
    {
        $maxKb = $this->intProperty($widgetInstance, 'max_kb', 300);
        $pending = $this->scheduleService->getItems(['status' => 'pending']);
        $connection = $this->resourceConnection->getConnection();
        $contents = $pending === [] ? [] : $connection->fetchPairs(
            $connection->select()->from(
                $this->resourceConnection->getTableName('hyva_commerce_cms_scheduled_item'),
                ['id', 'content']
            )
                ->where('id IN (?)', array_column($pending, 'item_id'))
        );
        $rows = [];
        $totals = ['large' => 0, 'missing_alt' => 0, 'broken' => 0, 'unoptimized' => 0, 'menu_links' => 0];
        $menuAvailable = $this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER);
        foreach ($pending as $item) {
            $decoded = json_decode(Cast::string($contents[$item['item_id']] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $document = $this->componentTree->normalizeDocument($decoded, $item['entity_type'], $item['entity_id']);
            $problems = [];
            foreach ($this->extractor->fromDocument($document) as $ref) {
                $usage = [
                    'source' => 'scheduled',
                    'entity_type' => $item['entity_type'],
                    'id' => $item['entity_id'],
                    'identifier' => '',
                    'edition' => 'scheduled',
                    'uid' => $ref['uid'],
                    'component' => $ref['component'],
                    'field' => $ref['field'],
                    'alt' => $ref['alt'],
                    'has_alt' => $ref['has_alt'],
                ];
                $check = $this->imageChecker->check(
                    $ref['path'],
                    [$usage],
                    [
                        'max_kb' => $maxKb,
                        'variants' => $this->mediaService->getVariants($ref['path']),
                        'output_formats' => $this->mediaService->getOutputFormats(),
                    ]
                );
                foreach ($check['issues'] as $issue) {
                    $key = match ($issue['code']) {
                        'oversized_file', 'heavy_hero', 'large_png' => 'large',
                        'missing_alt' => 'missing_alt',
                        'broken_reference' => 'broken',
                        'not_optimized', 'missing_webp', 'missing_avif' => 'unoptimized',
                        default => null,
                    };
                    if ($key !== null) {
                        $totals[$key]++;
                        $problems[] = $issue['code'] . ': ' . $ref['path'];
                    }
                }
            }
            if ($item['entity_type'] === 'menu' && $menuAvailable) {
                $menuIssues = $this->menuValidator->validate($document, ['store_ids' => [0], 'is_active' => true]);
                foreach ($menuIssues as $issue) {
                    if (in_array(
                        $issue['code'],
                        [
                                'broken_category',
                                'inactive_category',
                                'missing_product',
                                'disabled_product',
                                'missing_cms_page',
                            ],
                        true
                    )
                    ) {
                        $totals['menu_links']++;
                        $problems[] = $issue['code'] . ': ' . ($issue['label'] ?? '');
                    }
                }
            }
            $rows[] = $this->row(
                $item['release_name'] . ': ' . $item['name'],
                $problems === [] ? (string) __('ready') : implode('; ', array_slice(array_unique($problems), 0, 3)),
                $problems === [] ? 'ready' : count($problems) . ' issue(s)',
                $problems === [] ? 'success' : (str_contains(implode(' ', $problems), 'broken') ? 'danger' : 'warning'),
                $this->url->getUrl(
                    'liveview/editor/index',
                    ['type' => $item['entity_type'], 'id' => $item['entity_id']]
                )
            );
        }
        return [
            'stats' => [
                $this->stat('Scheduled items', count($pending)),
                $this->stat('Large images', $totals['large'], $this->level($totals['large'])),
                $this->stat('Missing alt', $totals['missing_alt'], $this->level($totals['missing_alt'])),
                $this->stat('Broken media', $totals['broken'], $this->level($totals['broken'], 'danger')),
                $this->stat('Not optimized yet', $totals['unoptimized'], $this->level($totals['unoptimized'])),
                $this->stat('Menu link problems', $totals['menu_links'], $this->level($totals['menu_links'], 'danger')),
            ],
            'sections' => [
                [
                    'title' => (string) __('Scheduled content'),
                    'rows' => $rows,
                    'empty' => (string) __('No pending scheduled content.'),
                ]
            ],
            'notice' => (string) __(
                'Checks the content stored in each pending scheduled item, i.e. exactly what will be published.'
            ),
            'links' => [],
        ];
    }
}
