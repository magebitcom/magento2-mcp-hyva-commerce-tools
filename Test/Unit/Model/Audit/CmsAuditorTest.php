<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Audit;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Audit\CmsAuditor;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationAnalyzer;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type ContentRow from ContentIndex
 */
class CmsAuditorTest extends TestCase
{
    /**
     * Rows mixing the editor flag and the content status, the mix that made the old buckets disagree.
     */
    public function testEveryGroupAddsUpToTheTotalAndMatchesTheListStatus(): void
    {
        $rows = [
            // Editor on, nothing saved yet: counted as "Hyvä enabled" before, but its status is not_hyva.
            $this->row(1, true, ContentIndex::STATUS_NOT_HYVA, false, false, ''),
            $this->row(2, true, ContentIndex::STATUS_PUBLISHED, true, true, ''),
            $this->row(3, true, ContentIndex::STATUS_PUBLISHED_WITH_DRAFT, true, true, ''),
            // Editor off with a converted draft: draft_only AND legacy (the storefront still shows Magento content).
            $this->row(4, false, ContentIndex::STATUS_DRAFT_ONLY, true, false, '<p>Old WYSIWYG text</p>'),
            $this->row(5, false, ContentIndex::STATUS_NOT_HYVA, false, false, '<div data-content-type="row">x</div>'),
            $this->row(6, false, ContentIndex::STATUS_NOT_HYVA, false, false, ''),
        ];

        $counts = $this->auditor()->countRows($rows);

        $this->assertSame(6, $counts['total']);
        $this->assertSame([
            ContentIndex::STATUS_PUBLISHED => 1,
            ContentIndex::STATUS_PUBLISHED_WITH_DRAFT => 1,
            ContentIndex::STATUS_DRAFT_ONLY => 1,
            ContentIndex::STATUS_NOT_HYVA => 3,
        ], $counts['status']);
        $perRow = array_count_values(array_column($rows, 'status'));
        ksort($perRow);
        $nonZero = array_filter($counts['status']);
        ksort($nonZero);
        $this->assertSame($perRow, $nonZero, 'status buckets equal the per-row status of page.list');
        $this->assertSame(['hyva_cms' => 3, 'magento' => 3], $counts['rendered_by']);
        $this->assertSame(2, $counts['legacy']['total']);
        $this->assertSame(1, $counts['legacy'][MigrationAnalyzer::KIND_PAGE_BUILDER]);
        $this->assertSame(1, $counts['legacy'][MigrationAnalyzer::KIND_WYSIWYG]);
        $this->assertSame(0, $counts['legacy'][MigrationAnalyzer::KIND_EMPTY]);
        $this->assertSame(1, $counts['legacy']['converted_drafts_waiting']);
        foreach (['status', 'rendered_by'] as $group) {
            $this->assertSame($counts['total'], array_sum($counts[$group]), $group . ' must add up to total');
        }
    }

    public function testNoRowsGivesZeroBuckets(): void
    {
        $counts = $this->auditor()->countRows([]);
        $this->assertSame(0, $counts['total']);
        $this->assertSame(0, array_sum($counts['status']) + array_sum($counts['rendered_by']));
    }

    /**
     * Auditor with the real ContentIndex legacy rule and MigrationAnalyzer.
     *
     * @return CmsAuditor
     */
    private function auditor(): CmsAuditor
    {
        $tree = new ComponentTree($this->createMock(ComponentProvider::class));
        $extractor = new ImageReferenceExtractor($tree, $this->createMock(ImageAssetPathResolverInterface::class));
        return new CmsAuditor(
            new ContentIndex($this->createMock(ResourceConnection::class)),
            $tree,
            $extractor,
            $this->createMock(MediaFileInfo::class),
            new MigrationAnalyzer($extractor),
            $this->createMock(ScheduleService::class),
            $this->createMock(ConflictReport::class)
        );
    }

    /**
     * Row as ContentIndex::getRows($type, true) returns it.
     *
     * @param int $id
     * @param bool $liveview
     * @param string $status
     * @param bool $hasDraft
     * @param bool $isPublished
     * @param string $magentoContent
     * @return array
     * @phpstan-return ContentRow
     */
    private function row(
        int $id,
        bool $liveview,
        string $status,
        bool $hasDraft,
        bool $isPublished,
        string $magentoContent
    ): array {
        return [
            'type' => 'cms_page',
            'id' => $id,
            'identifier' => 'p' . $id,
            'title' => 'P' . $id,
            'is_active' => true,
            'store_ids' => [0],
            'has_hyva_row' => $liveview || $hasDraft,
            'liveview_enabled' => $liveview,
            'status' => $status,
            'has_draft' => $hasDraft,
            'is_published' => $isPublished,
            'draft_differs' => false,
            'uses_page_builder' => str_contains($magentoContent, 'data-content-type='),
            'has_magento_content' => $magentoContent !== '',
            'updated_at' => '',
            'magento_content' => $magentoContent,
        ];
    }
}
