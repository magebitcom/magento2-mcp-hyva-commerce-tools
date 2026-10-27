<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageCreate;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use PHPUnit\Framework\TestCase;

class PageCreateConfirmTest extends TestCase
{
    use StoreScopeStub;

    public function testAnActiveCreateWithoutConfirmWritesNothing(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('create');
        $contentService = $this->contentService();
        $contentService->expects($this->never())->method('saveDraft');

        $payload = $this->payload($this->tool($writer, $contentService)->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
            'is_active' => true,
        ]));

        $this->assertTrue($payload['confirmation_required'] ?? null);
        $this->assertSame('create_active', $payload['action'] ?? null);
        $affectedList = $payload['affected'] ?? null;
        $this->assertIsArray($affectedList);
        $affected = $affectedList[0] ?? null;
        $this->assertIsArray($affected);
        $this->assertSame('sale', $affected['identifier'] ?? null);
        $changes = $affected['changes'] ?? null;
        $this->assertIsArray($changes);
        $this->assertSame(['is_active'], array_column($changes, 'field'));
    }

    public function testConfirmCreatesTheActivePage(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('create')
            ->with('cms_page', ['title' => 'Sale', 'identifier' => 'sale', 'is_active' => true])
            ->willReturn(12);
        $contentService = $this->contentService();
        $contentService->expects($this->once())->method('saveDraft');

        $payload = $this->payload($this->tool($writer, $contentService)->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
            'is_active' => true,
            'confirm' => true,
        ]));

        $this->assertTrue($payload['created'] ?? null);
        $this->assertSame(12, $payload['id'] ?? null);
    }

    public function testAnInactiveCreateNeedsNoConfirm(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('create')->willReturn(12);

        $payload = $this->payload($this->tool($writer, $this->contentService())->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
            'store_ids' => [1],
            'is_active' => false,
        ]));

        $this->assertArrayNotHasKey('confirmation_required', $payload);
        $this->assertTrue($payload['created'] ?? null);
    }

    public function testARestrictedRoleCannotCreateForAllStoreViews(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('create');
        $scopes = [1];

        $result = $this->tool($writer, $this->contentService(), $scopes)->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
        ]);

        $this->assertTrue($result->isError());
        $payload = $this->payload($result);
        $this->assertSame(403, $payload['status'] ?? null);
        $this->assertSame([0], $payload['denied_store_ids'] ?? null);
    }

    public function testARestrictedRoleCannotCreateForAnotherStoreView(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('create');
        $scopes = [1];

        $payload = $this->payload($this->tool($writer, $this->contentService(), $scopes)->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
            'store_ids' => [1, 2],
        ]));

        $this->assertSame([2], $payload['denied_store_ids'] ?? null);
    }

    public function testARestrictedRoleCreatesInsideItsScope(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('create')->willReturn(12);
        $scopes = [1, 2];

        $payload = $this->payload($this->tool($writer, $this->contentService(), $scopes)->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
            'store_ids' => [2],
        ]));

        $this->assertTrue($payload['created'] ?? null);
    }

    public function testARoleHoldingEveryStoreViewCreatesForAllStoreViews(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('create')->willReturn(12);

        $payload = $this->payload($this->tool($writer, $this->contentService(), [1, 2])->execute([
            'title' => 'Sale',
            'identifier' => 'sale',
        ]));

        $this->assertTrue($payload['created'] ?? null);
    }

    /**
     * Content service that accepts any content.
     *
     * @return ContentService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function contentService(): ContentService
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('validate')->willReturn(['valid' => true, 'schema_error' => null, 'issues' => []]);
        return $contentService;
    }

    /**
     * Page create tool for a caller with the given Advanced Admin Permissions scopes.
     *
     * @param EntityWriter $writer
     * @param ContentService $contentService
     * @param list<int>|null $scopes Store views of a restricted caller; null = unrestricted.
     * @return PageCreate
     */
    private function tool(
        EntityWriter $writer,
        ContentService $contentService,
        ?array $scopes = null
    ): PageCreate {
        return new PageCreate(
            $writer,
            $contentService,
            $this->createMock(ComponentTree::class),
            $this->createMock(DraftEditor::class),
            $this->createMock(ContentLocator::class),
            $this->createMock(PreviewService::class),
            new LiveSettings(),
            $this->createMock(ContentAcl::class),
            $this->storeScope($scopes)
        );
    }

    /**
     * Decoded JSON payload of a tool result.
     *
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
