<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Audit;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Audit\CmsAuditor;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationAnalyzer;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The content audit behind hyva_commerce.audit.cms and the Content Health widget reads each content type once.
 *
 * @phpstan-import-type ContentRow from ContentIndex
 */
class CmsAuditorScanTest extends TestCase
{
    /**
     * @var ContentIndex&MockObject
     */
    private ContentIndex $contentIndex;

    /**
     * @var MediaFileInfo&MockObject
     */
    private MediaFileInfo $mediaFileInfo;

    /**
     * @var ConflictReport&MockObject
     */
    private ConflictReport $conflictReport;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->contentIndex = $this->createMock(ContentIndex::class);
        $this->mediaFileInfo = $this->createMock(MediaFileInfo::class);
        $this->conflictReport = $this->createMock(ConflictReport::class);
    }

    public function testTheIndexIsReadOncePerTypeAndFeedsCountsAndDocuments(): void
    {
        $read = [];
        $this->contentIndex->expects($this->exactly(2))->method('getRows')->willReturnCallback(
            function (string $type, bool $withContent) use (&$read): array {
                $read[] = $type;
                $this->assertTrue($withContent, 'the document scan needs the content');
                return [$this->row($type, 1), $this->row($type, 2)];
            }
        );
        $this->mediaFileInfo->expects($this->once())->method('exists')->with('wysiwyg/a.jpg')->willReturn(false);

        $report = $this->auditor()->audit(100);

        $this->assertSame([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK], $read);
        $this->assertSame(2, $report['counts'][ContentLocator::TYPE_PAGE]['total']);
        $this->assertSame(2, $report['counts'][ContentLocator::TYPE_BLOCK]['total']);
        // 2 types x 2 rows x 2 editions, one image each.
        $this->assertSame(8, $report['content_issues']['missing_alt']);
        $this->assertSame(8, $report['content_issues']['broken_images']);
    }

    public function testTheIssueListIsBoundedAndErrorsComeFirstWhileCountsStayComplete(): void
    {
        $this->contentIndex->method('getRows')->willReturnCallback(
            fn (string $type): array => [$this->row($type, 1), $this->row($type, 2), $this->row($type, 3)]
        );
        $this->mediaFileInfo->method('exists')->willReturn(false);
        $this->conflictReport->method('getConflicts')->willReturn([
            ['severity' => 'warning', 'code' => 'overlap', 'message' => 'w', 'release_ids' => [], 'item_ids' => []],
        ]);

        $report = $this->auditor()->audit(3);

        $this->assertCount(3, $report['issues']);
        $this->assertSame(['error', 'error', 'error'], array_column($report['issues'], 'severity'));
        $this->assertSame('broken_image', $report['issues'][0]['code']);
        $this->assertSame(12, $report['content_issues']['missing_alt']);
        $this->assertSame(12, $report['content_issues']['broken_images']);

        $all = $this->auditor()->audit(100);
        $this->assertCount(25, $all['issues']);
        $this->assertSame('schedule_overlap', $all['issues'][24]['code']);
    }

    /**
     * @return CmsAuditor
     */
    private function auditor(): CmsAuditor
    {
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('parse')->willReturnCallback(
            static fn (string $json, string $type, int $id): array => ['type' => $type, 'id' => $id]
        );
        $tree->method('validate')->willReturn([]);
        $extractor = $this->createMock(ImageReferenceExtractor::class);
        $extractor->method('fromDocument')->willReturn([[
            'path' => 'wysiwyg/a.jpg',
            'alt' => null,
            'has_alt' => false,
            'uid' => 'img1',
            'component' => 'image',
            'field' => 'image',
            'kind' => 'image',
        ]]);
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('getReleases')->willReturn([]);
        $schedule->method('getItems')->willReturn([]);
        return new CmsAuditor(
            $this->contentIndex,
            $tree,
            $extractor,
            $this->mediaFileInfo,
            $this->createMock(MigrationAnalyzer::class),
            $schedule,
            $this->conflictReport,
            new ContentScanner(
                $this->contentIndex,
                $tree,
                $this->createMock(ResourceConnection::class),
                $this->createMock(FeatureCheckerInterface::class)
            )
        );
    }

    /**
     * Row as ContentIndex::getRows($type, true) returns it, with a draft and a published edition.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @phpstan-return ContentRow
     */
    private function row(string $type, int $id): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'identifier' => $type . $id,
            'title' => 'T' . $id,
            'is_active' => true,
            'store_ids' => [0],
            'has_hyva_row' => true,
            'liveview_enabled' => true,
            'status' => ContentIndex::STATUS_PUBLISHED_WITH_DRAFT,
            'has_draft' => true,
            'is_published' => true,
            'draft_differs' => true,
            'uses_page_builder' => false,
            'has_magento_content' => false,
            'updated_at' => '',
            'draft_content' => '{"content":[{"uid":"d"}]}',
            'published_content' => '{"content":[{"uid":"p"}]}',
            'magento_content' => '',
        ];
    }
}
