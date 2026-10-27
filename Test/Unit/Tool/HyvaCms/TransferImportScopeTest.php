<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Hyva\CmsImportExport\Model\Import\ContentProcessor;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Transfer\TransferImport;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * hyva_cms.import refuses targets outside the caller's store view restriction.
 */
class TransferImportScopeTest extends TestCase
{
    use StoreScopeStub;

    private const PAYLOAD = ['draft_content' => ['content' => []], 'entity' => ['identifier' => 'x', 'title' => 'X']];

    /**
     * @var EntityWriter&MockObject
     */
    private EntityWriter $writer;

    /**
     * @var ContentService&MockObject
     */
    private ContentService $contentService;

    /**
     * @var ContentLocator&MockObject
     */
    private ContentLocator $locator;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(EntityWriter::class);
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('validate')
            ->willReturn(['valid' => true, 'schema_error' => null, 'issues' => []]);
        $this->locator = $this->createMock(ContentLocator::class);
    }

    public function testANewEntityForAllStoreViewsIsRefusedToARestrictedRole(): void
    {
        $this->writer->expects($this->never())->method('create');
        $this->contentService->expects($this->never())->method('saveDraft');
        $result = $this->tool([1])->execute(
            ['entity_type' => 'cms_page', 'payload' => self::PAYLOAD, 'create_new' => true]
        );
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testANewEntityForTheOwnStoreViewIsCreated(): void
    {
        $this->writer->expects($this->once())->method('create')->willReturn(12);
        $payload = self::PAYLOAD;
        $payload['entity']['stores'] = [1];
        $result = $this->tool([1])->execute(['entity_type' => 'cms_page', 'payload' => $payload, 'create_new' => true]);
        $this->assertFalse($result->isError(), json_encode($result->getContent()) ?: '');
    }

    public function testAnExistingTargetIsLocatedForWriting(): void
    {
        $this->locator->expects($this->never())->method('locate');
        $this->locator->expects($this->once())->method('locateForWrite')
            ->willThrowException(new AuthorizationException(__('outside')));
        $this->contentService->expects($this->never())->method('saveDraft');
        $result = $this->tool([1])->execute(['entity_type' => 'cms_page', 'payload' => self::PAYLOAD, 'id' => 2]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    /**
     * Tool for a caller restricted to the store views.
     *
     * @param array $allowedStoreIds
     * @return TransferImport
     * @phpstan-param list<int> $allowedStoreIds
     */
    private function tool(array $allowedStoreIds): TransferImport
    {
        $processor = $this->createMock(ContentProcessor::class);
        $processor->method('processForImport')->willReturnArgument(0);
        $processor->method('getWarnings')->willReturn([]);
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('normalizeDocument')->willReturnArgument(0);
        $tree->method('countComponents')->willReturn([]);
        $images = $this->createMock(ImageReferenceExtractor::class);
        $images->method('fromDocument')->willReturn([]);
        return new TransferImport(
            $this->locator,
            $this->contentService,
            $this->createMock(ContentIndex::class),
            $tree,
            $processor,
            $this->writer,
            $images,
            $this->createMock(MediaFileInfo::class),
            $this->createMock(ContentAcl::class),
            $this->storeScope($allowedStoreIds)
        );
    }
}
