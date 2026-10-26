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
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationAnalyzer;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;

/**
 * Hyvä CMS content health: counts per state, broken/invalid content, image problems, schedule problems.
 * Shared by hyva_commerce.audit.cms and the content-health / migration dashboard widgets.
 *
 * @phpstan-import-type ContentRow from ContentIndex
 * @phpstan-type EntityCounts array{total: int, status: array<string, int>, rendered_by: array<string, int>,
 *     legacy: array<string, int>}
 */
class CmsAuditor
{
    /**
     * @param ContentIndex $contentIndex
     * @param ContentScanner $contentScanner
     * @param ComponentTree $componentTree
     * @param ImageReferenceExtractor $imageReferenceExtractor
     * @param MediaFileInfo $mediaFileInfo
     * @param MigrationAnalyzer $migrationAnalyzer
     * @param ScheduleService $scheduleService
     * @param ConflictReport $conflictReport
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ContentScanner $contentScanner,
        private readonly ComponentTree $componentTree,
        private readonly ImageReferenceExtractor $imageReferenceExtractor,
        private readonly MediaFileInfo $mediaFileInfo,
        private readonly MigrationAnalyzer $migrationAnalyzer,
        private readonly ScheduleService $scheduleService,
        private readonly ConflictReport $conflictReport
    ) {
    }

    /**
     * Audit.
     *
     * @param int $issueLimit
     * @return array
     * @phpstan-return array{counts: array<string, EntityCounts>, scheduling: array<string, int>,
     *     content_issues: array<string, int>, issues: list<array<string, mixed>>}
     */
    public function audit(int $issueLimit = 100): array
    {
        $counts = [];
        foreach ([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK] as $type) {
            $counts[$type] = $this->countRows($this->contentIndex->getRows($type, true));
        }

        $issues = [];
        $contentIssues = [
            'invalid_json' => 0,
            'validation_errors' => 0,
            'unknown_components' => 0,
            'disabled_components' => 0,
            'missing_alt' => 0,
            'broken_images' => 0,
        ];
        $documents = $this->contentScanner->getDocuments([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK]);
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
                $issues[] = $where + [
                    'code' => 'invalid_json',
                    'severity' => 'error',
                    'message' => (string) $entry['error'],
                ];
                continue;
            }
            foreach ($this->componentTree->validate($entry['document']) as $issue) {
                if ($issue['severity'] !== 'error') {
                    continue;
                }
                $contentIssues['validation_errors']++;
                $contentIssues['unknown_components'] += $issue['code'] === 'unknown_component' ? 1 : 0;
                $contentIssues['disabled_components'] += $issue['code'] === 'disabled_component' ? 1 : 0;
                $issues[] = $where + [
                    'code' => $issue['code'],
                    'severity' => 'error',
                    'uid' => $issue['uid'],
                    'message' => $issue['message'],
                ];
            }
            foreach ($this->imageReferenceExtractor->fromDocument($entry['document']) as $image) {
                if (!$image['has_alt']) {
                    $contentIssues['missing_alt']++;
                    $issues[] = $where + [
                        'code' => 'missing_alt',
                        'severity' => 'warning',
                        'uid' => $image['uid'],
                        'message' => sprintf('Image %s has no alt text.', $image['path']),
                    ];
                }
                if (!$this->mediaFileInfo->exists($image['path'])) {
                    $contentIssues['broken_images']++;
                    $issues[] = $where + [
                        'code' => 'broken_image',
                        'severity' => 'error',
                        'uid' => $image['uid'],
                        'message' => sprintf('Image %s does not exist in pub/media.', $image['path']),
                    ];
                }
            }
        }

        $conflicts = $this->conflictReport->getConflicts();
        $scheduling = [
            'pending_releases' => count($this->scheduleService->getReleases('pending')),
            'pending_items' => count($this->scheduleService->getItems(['status' => 'pending'])),
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
                $issues[] = [
                    'code' => 'schedule_' . $conflict['code'],
                    'severity' => $conflict['severity'],
                    'message' => $conflict['message'],
                ];
            }
        }

        $rank = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort(
            $issues,
            static fn (array $a, array $b): int => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3)
        );
        return [
            'counts' => $counts,
            'scheduling' => $scheduling,
            'content_issues' => $contentIssues,
            'issues' => array_slice($issues, 0, $issueLimit),
        ];
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
