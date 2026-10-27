<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Hyva\CmsConnect\Exception\ConflictException;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Hyva\CmsLiveviewEditor\Model\Image\ImageIdBackfiller;
use Hyva\CmsLiveviewEditor\Model\Security\ContentDocumentGuard;
use Hyva\CmsLiveviewEditor\Model\JsonSchema;
use Hyva\CmsLiveviewEditor\Model\Provider as ContentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContentServiceTest extends TestCase
{
    /**
     * @var MockObject
     */
    private MockObject $provider;

    /**
     * @var ComponentTree
     */
    private ComponentTree $tree;

    /**
     * @var ContentService
     */
    private ContentService $service;

    /**
     * @var string
     */
    private string $storedDraft = '';

    protected function setUp(): void
    {
        $components = ['row' => ['template' => 'row.phtml', 'children' => true]];
        $registry = $this->createMock(ComponentProvider::class);
        $registry->method('getComponents')->willReturn($components);
        $registry->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $this->tree = new ComponentTree($registry);
        $schema = $this->createMock(JsonSchema::class);
        $schema->method('validateContentGroup')->willReturn(['isValid' => true]);
        $context = $this->createMock(CallerContext::class);
        $context->method('adminUsername')->willReturn(null);
        $this->provider = $this->createMock(ContentProvider::class);
        $this->provider->method('getVersionHistory')->willReturn([]);
        $this->provider->method('getDraftContentJson')->willReturnCallback(fn (): string => $this->storedDraft);
        $backfiller = $this->createMock(ImageIdBackfiller::class);
        $backfiller->method('backfill')->willReturnArgument(0);
        $this->service = new ContentService(
            $this->provider,
            $schema,
            $this->tree,
            $context,
            $backfiller,
            $this->createMock(ContentDocumentGuard::class)
        );
    }

    public function testSaveDraftNeverPublishes(): void
    {
        $document = $this->tree->insertNode($this->tree->newDocument('cms_page', 5), $this->tree->createNode('row'));
        $this->provider->expects($this->once())
            ->method('saveContent')
            ->with('cms_page', 5, $this->callback(static function (string $json): bool {
                $data = json_decode($json, true);
                $version = is_array($data) ? ($data['version'] ?? null) : null;
                return is_array($version) && ($version['version_state'] ?? null) === 'draft'
                    && ($version['author'] ?? null) === 'MCP';
            }), false)
            ->willReturn(new DataObject());
        $this->provider->expects($this->once())->method('saveVersionHistoryItem')->with(
            'cms_page',
            5,
            $this->anything(),
            false
        );

        $this->service->saveDraft('cms_page', 5, $document);
    }

    public function testInvalidDocumentIsNeverSaved(): void
    {
        $document = $this->tree->insertNode(
            $this->tree->newDocument('cms_page', 5),
            ['uid' => 'u', 'component' => 'unknown', 'children' => []]
        );
        $this->provider->expects($this->never())->method('saveContent');
        $this->expectException(LocalizedException::class);
        $this->service->saveDraft('cms_page', 5, $document);
    }

    public function testPublishWithoutDraftFails(): void
    {
        $this->provider->expects($this->never())->method('saveContent');
        $this->expectException(LocalizedException::class);
        $this->service->publish('cms_page', 5);
    }

    public function testStaleBaseVersionIsRefusedWithoutSaving(): void
    {
        $this->storedDraft = (string) json_encode(['version' => ['uid' => 'saved-elsewhere'], 'content' => []]);
        $document = $this->tree->insertNode($this->tree->newDocument('cms_page', 5), $this->tree->createNode('row'));
        $this->provider->expects($this->never())->method('saveContent');
        $this->expectException(ConflictException::class);
        $this->service->saveDraft('cms_page', 5, $document, null, 'what-i-read');
    }

    public function testMatchingBaseVersionSaves(): void
    {
        $this->storedDraft = (string) json_encode(['version' => ['uid' => 'what-i-read'], 'content' => []]);
        $document = $this->tree->insertNode($this->tree->newDocument('cms_page', 5), $this->tree->createNode('row'));
        $this->provider->expects($this->once())->method('saveContent')->willReturn(new DataObject());
        $this->service->saveDraft('cms_page', 5, $document, null, 'what-i-read');
    }

    public function testStampTurnsNullTimestampsFromHyvaRestoresIntoStrings(): void
    {
        $restored = ['version' => ['uid' => 'r1', 'last_publish_timestamp' => null, 'last_save_timestamp' => null]];
        $version = $this->service->stamp($restored, 'draft')['version'] ?? null;
        $this->assertIsArray($version);
        $this->assertSame('', $version['last_publish_timestamp']);
        $this->assertNotSame('', $version['last_save_timestamp']);
        $published = $this->service->stamp($restored, 'published')['version'] ?? null;
        $this->assertIsArray($published);
        $this->assertNotSame('', $published['last_publish_timestamp']);
    }
}
