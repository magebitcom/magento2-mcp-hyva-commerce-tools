<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Audit;

use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationAnalyzer;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Hyvä CMS content health: counts per state, broken/invalid content, image problems, schedule problems.
 * Shared by hyva_commerce.audit.cms and the content-health / migration dashboard widgets. A caller restricted to
 * some websites/store views gets the health of the pages, blocks and scheduled items it may see.
 *
 * @phpstan-import-type ContentRow from ContentIndex
 * @phpstan-type EntityCounts array{total: int, status: array<string, int>, rendered_by: array<string, int>,
 *     legacy: array<string, int>}
 */
class CmsAuditor
{
    private const SEVERITY_RANK = ['error' => 0, 'warning' => 1, 'info' => 2];

    /**
     * @param ContentIndex $contentIndex
     * @param ComponentTree $componentTree
     * @param ImageReferenceExtractor $imageReferenceExtractor
     * @param MediaFileInfo $mediaFileInfo
     * @param MigrationAnalyzer $migrationAnalyzer
     * @param ScheduleService $scheduleService
     * @param ConflictReport $conflictReport
     * @param ContentScanner $contentScanner
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ComponentTree $componentTree,
        private readonly ImageReferenceExtractor $imageReferenceExtractor,
        private readonly MediaFileInfo $mediaFileInfo,
        private readonly MigrationAnalyzer $migrationAnalyzer,
        private readonly ScheduleService $scheduleService,
        private readonly ConflictReport $conflictReport,
        private readonly ContentScanner $contentScanner,
        private readonly ContentScopeFilter $scopeFilter
    ) {
    }

    /**
     * Audit.
     *
     * Each content type is read once: the same rows feed the counts and the document scan. The issue list keeps at
     * most $issueLimit entries per severity while scanning, so the work held in memory stays bounded on the
     * dashboard path whatever the size of the catalogue, and each image path is checked on disk once.
     *
     * @param int $issueLimit
     * @return array
     * @phpstan-return array{counts: array<string, EntityCounts>, scheduling: array<string, int>,
     *     content_issues: array<string, int>, issues: list<array<string, mixed>>,
     *     entities_hidden_by_permissions?: int}
     */
    public function audit(int $issueLimit = 100): array
    {
        $issueLimit = max(0, $issueLimit);
        $counts = [];
        $documents = [];
        $hidden = 0;
        foreach ([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK] as $type) {
            // The rows carry their store views, so the scope costs no query.
            $scoped = $this->scopeFilter->filterByStoreIds($this->contentIndex->getRows($type, true));
            $rows = $scoped['visible'];
            $hidden += $scoped['hidden'];
            $counts[$type] = $this->countRows($rows);
            array_push($documents, ...$this->contentScanner->documentsOfRows($type, $rows));
        }

        $buckets = [];
        $contentIssues = [
            'invalid_json' => 0,
            'validation_errors' => 0,
            'unknown_components' => 0,
            'disabled_components' => 0,
            'missing_alt' => 0,
            'broken_images' => 0,
        ];
        $fileExists = [];
        $this->imageReferenceExtractor->prefetchAssetIds(array_column($documents, 'document'));
        foreach ($documents as $entry) {
            $where = [
                'entity_type' => $entry['entity_type'],
                'id' => $entry['id'],
                'identifier' => $entry['identifier'],
                'edition' => $entry['edition'],
            ];
            if ($entry['document'] === null) {
                $contentIssues['invalid_json']++;
                $this->addIssue($buckets, $issueLimit, $where + [
                    'code' => 'invalid_json',
                    'severity' => 'error',
                    'message' => (string) $entry['error'],
                ]);
                continue;
            }
            foreach ($this->componentTree->validate($entry['document']) as $issue) {
                if ($issue['severity'] !== 'error') {
                    continue;
                }
                $contentIssues['validation_errors']++;
                $contentIssues['unknown_components'] += $issue['code'] === 'unknown_component' ? 1 : 0;
                $contentIssues['disabled_components'] += $issue['code'] === 'disabled_component' ? 1 : 0;
                $this->addIssue($buckets, $issueLimit, $where + [
                    'code' => $issue['code'],
                    'severity' => 'error',
                    'uid' => $issue['uid'],
                    'message' => $issue['message'],
                ]);
            }
            foreach ($this->imageReferenceExtractor->fromDocument($entry['document']) as $image) {
                if (!$image['has_alt']) {
                    $contentIssues['missing_alt']++;
                    $this->addIssue($buckets, $issueLimit, $where + [
                        'code' => 'missing_alt',
                        'severity' => 'warning',
                        'uid' => $image['uid'],
                        'message' => sprintf('Image %s has no alt text.', $image['path']),
                    ]);
                }
                $fileExists[$image['path']] ??= $this->mediaFileInfo->exists($image['path']);
                if (!$fileExists[$image['path']]) {
                    $contentIssues['broken_images']++;
                    $this->addIssue($buckets, $issueLimit, $where + [
                        'code' => 'broken_image',
                        'severity' => 'error',
                        'uid' => $image['uid'],
                        'message' => sprintf('Image %s does not exist in pub/media.', $image['path']),
                    ]);
                }
            }
        }

        $conflicts = $this->conflictReport->getConflictsWithinCallerScope()['conflicts'];
        $pendingItems = $this->scopeFilter->filter(
            $this->scheduleService->getItems(['status' => 'pending']),
            'entity_type',
            'entity_id'
        )['visible'];
        $scheduling = [
            // A restricted caller sees the releases holding content it may see.
            'pending_releases' => $this->scopeFilter->isRestricted()
                ? count(array_unique(array_column($pendingItems, 'release_id')))
                : count($this->scheduleService->getReleases('pending')),
            'pending_items' => count($pendingItems),
            'conflict_errors' => count(
                array_filter($conflicts, static fn (array $c): bool => $c['severity'] === 'error')
            ),
            'conflict_warnings' => count(
                array_filter($conflicts, static fn (array $c): bool => $c['severity'] === 'warning')
            ),
            'overdue_releases' => count(
                array_filter($conflicts, static fn (array $c): bool => $c['code'] === 'overdue')
            ),
        ];
        foreach ($conflicts as $conflict) {
            if ($conflict['severity'] !== 'info') {
                $this->addIssue($buckets, $issueLimit, [
                    'code' => 'schedule_' . $conflict['code'],
                    'severity' => $conflict['severity'],
                    'message' => $conflict['message'],
                ]);
            }
        }

        ksort($buckets);
        $report = [
            'counts' => $counts,
            'scheduling' => $scheduling,
            'content_issues' => $contentIssues,
            'issues' => array_slice(array_merge([], ...array_values($buckets)), 0, $issueLimit),
        ];
        if ($this->scopeFilter->isRestricted()) {
            $report['entities_hidden_by_permissions'] = $hidden;
        }
        return $report;
    }

    /**
     * Keep an issue in its severity bucket while the bucket holds fewer than $limit issues.
     *
     * @param array $buckets
     * @param int $limit
     * @param array $issue
     * @return void
     * @phpstan-param array<int, list<array<string, mixed>>> $buckets
     * @phpstan-param array<string, mixed> $issue
     */
    private function addIssue(array &$buckets, int $limit, array $issue): void
    {
        $rank = self::SEVERITY_RANK[Cast::string($issue['severity'] ?? '')] ?? count(self::SEVERITY_RANK);
        if (count($buckets[$rank] ?? []) < $limit) {
            $buckets[$rank][] = $issue;
        }
    }

    /**
     * Counts for one entity type.
     *
     * The status and rendered_by groups partition the rows (each adds up to `total`); legacy is a subset (its
     * kinds add up to legacy.total, converted_drafts_waiting is a subset of that).
     *
     * - status: the same Hyvä CMS status page.list / block.list report per row.
     * - rendered_by: who renders the storefront: hyva_cms (Liveview editor enabled) or magento.
     * - legacy: rows rendered by Magento that still have Magento content (= migration.scan), by kind.
     *
     * @param array $rows
     * @return array
     * @phpstan-param list<ContentRow> $rows
     * @phpstan-return EntityCounts
     */
    public function countRows(array $rows): array
    {
        $status = array_fill_keys([
            ContentIndex::STATUS_PUBLISHED,
            ContentIndex::STATUS_PUBLISHED_WITH_DRAFT,
            ContentIndex::STATUS_DRAFT_ONLY,
            ContentIndex::STATUS_NOT_HYVA,
        ], 0);
        $renderedBy = ['hyva_cms' => 0, 'magento' => 0];
        $legacy = [
            'total' => 0,
            MigrationAnalyzer::KIND_PAGE_BUILDER => 0,
            MigrationAnalyzer::KIND_WYSIWYG => 0,
            MigrationAnalyzer::KIND_EMPTY => 0,
            'converted_drafts_waiting' => 0,
        ];
        foreach ($rows as $row) {
            $status[$row['status']] = ($status[$row['status']] ?? 0) + 1;
            $renderedBy[$row['liveview_enabled'] ? 'hyva_cms' : 'magento']++;
            if ($this->contentIndex->isLegacy($row)) {
                $legacy['total']++;
                $kind = $this->migrationAnalyzer->analyze($row['magento_content'] ?? '')['kind'];
                $legacy[$kind] = ($legacy[$kind] ?? 0) + 1;
                $legacy['converted_drafts_waiting'] += $row['has_draft'] && !$row['is_published'] ? 1 : 0;
            }
        }
        return ['total' => count($rows), 'status' => $status, 'rendered_by' => $renderedBy, 'legacy' => $legacy];
    }
}
