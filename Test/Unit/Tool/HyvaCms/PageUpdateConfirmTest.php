<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magento\Framework\Exception\LocalizedException;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageUpdate;
use PHPUnit\Framework\TestCase;

class PageUpdateConfirmTest extends TestCase
{
    use StoreScopeStub;

    private const PAGE = ['type' => 'cms_page', 'id' => 7, 'identifier' => 'sale', 'title' => 'Sale',
        'is_active' => false, 'store_ids' => [1]];

    public function testALiveSettingWithoutConfirmWritesNothing(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->expects($this->never())->method('saveDraft');
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('update');

        $payload = $this->payload($this->tool($contentService, $writer)->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['is_active' => true],
        ]));

        $this->assertTrue($payload['confirmation_required'] ?? null);
        $affected = $payload['affected'] ?? null;
        $this->assertIsArray($affected);
        $this->assertSame(['is_active'], array_column($affected, 'field'));
    }

    public function testConfirmAppliesTheSetting(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('update')
            ->with('cms_page', 7, ['is_active' => true])->willReturn(['is_active']);

        $payload = $this->payload($this->tool($this->createMock(ContentService::class), $writer)->execute([
            'identifier' => 'sale',
            'settings' => ['is_active' => true],
            'confirm' => true,
        ]));

        $this->assertSame(['is_active'], $payload['settings_changed'] ?? null);
    }

    public function testASettingOfAnInactivePageNeedsNoConfirm(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('update')->willReturn(['title']);

        $payload = $this->payload($this->tool($this->createMock(ContentService::class), $writer)->execute([
            'identifier' => 'sale',
            'settings' => ['title' => 'Summer sale'],
        ]));

        $this->assertArrayNotHasKey('confirmation_required', $payload);
    }

    public function testAnUnchangedMetaFieldOfAnActivePageIsNoChange(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('currentValues')
            ->with('cms_page', 7, ['meta_title'])->willReturn(['meta_title' => 'Sale | Shop']);
        $writer->expects($this->once())->method('update')->willReturn(['meta_title']);

        $payload = $this->payload($this->tool($this->createMock(ContentService::class), $writer, null, true)->execute([
            'identifier' => 'sale',
            'settings' => ['meta_title' => 'Sale | Shop', 'title' => 'Sale'],
        ]));

        $this->assertArrayNotHasKey('confirmation_required', $payload);
    }

    public function testAChangedMetaFieldOfAnActivePageShowsTheStoredValue(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->method('currentValues')->willReturn(['meta_title' => 'Sale | Shop']);
        $writer->expects($this->never())->method('update');

        $payload = $this->payload($this->tool($this->createMock(ContentService::class), $writer, null, true)->execute([
            'identifier' => 'sale',
            'settings' => ['meta_title' => 'Summer sale | Shop'],
        ]));

        $this->assertTrue($payload['confirmation_required'] ?? null);
        $affected = $payload['affected'] ?? null;
        $this->assertIsArray($affected);
        $this->assertSame('Sale | Shop', $affected[0]['from'] ?? null);
    }

    public function testARestrictedRoleMayNotMoveAPageToAllStoreViews(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('update');

        $result = $this->tool($this->createMock(ContentService::class), $writer, [1])->execute([
            'identifier' => 'sale',
            'settings' => ['store_ids' => [0]],
            'confirm' => true,
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testARestrictedRoleChangesAPageOfItsOwnStoreView(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('update')->willReturn(['title']);

        $payload = $this->payload($this->tool($this->createMock(ContentService::class), $writer, [1])->execute([
            'identifier' => 'sale',
            'settings' => ['title' => 'Summer sale', 'store_ids' => [1]],
        ]));

        $this->assertSame(['title'], $payload['settings_changed'] ?? null);
    }

    public function testAFailingSettingsSaveReportsTheAlreadySavedDraft(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->expects($this->once())->method('saveDraft')->willReturn(['version_uid' => 'v1']);
        $editor = $this->createMock(DraftEditor::class);
        $editor->method('apply')->willReturn(['document' => ['content' => []], 'applied' => []]);
        $writer = $this->createMock(EntityWriter::class);
        $writer->method('update')->willThrowException(new LocalizedException(__('Could not save')));
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn(['is_active' => false] + self::PAGE);
        $tool = new PageUpdate(
            $locator,
            $contentService,
            $this->createMock(ComponentTree::class),
            $editor,
            $writer,
            new LiveSettings(),
            $this->storeScope(null)
        );

        $result = $tool->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['title' => 'Summer sale'],
        ]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertTrue($payload['draft_saved'] ?? null);
        $this->assertFalse($payload['settings_applied'] ?? null);
        $this->assertSame(['version_uid' => 'v1'], $payload['draft'] ?? null);
    }

    /**
     * Page update over the "sale" page.
     *
     * @param ContentService $contentService
     * @param EntityWriter $writer
     * @param array|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param bool $active Whether the page is active.
     * @return PageUpdate
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function tool(
        ContentService $contentService,
        EntityWriter $writer,
        ?array $allowedStoreIds = null,
        bool $active = false
    ): PageUpdate {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn(['is_active' => $active] + self::PAGE);
        return new PageUpdate(
            $locator,
            $contentService,
            $this->createMock(ComponentTree::class),
            $this->createMock(DraftEditor::class),
            $writer,
            new LiveSettings(),
            $this->storeScope($allowedStoreIds)
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
