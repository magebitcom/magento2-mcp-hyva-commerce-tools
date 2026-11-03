<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Hyva\CmsConnect\Exception\ConflictException;
use Magento\Framework\Exception\LocalizedException;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block\BlockUpdate;
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

    public function testARestrictedRoleMayMoveAnAllStoreViewsPageIntoItsStoreViews(): void
    {
        // T-32: Hyvä Advanced Admin Permissions judges a re-assignment by the store views it enters.
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('update')->willReturn(['store_ids']);

        $payload = $this->payload($this->tool($this->createMock(ContentService::class), $writer, [1], false, [0])
            ->execute(['identifier' => 'sale', 'settings' => ['store_ids' => [1]], 'confirm' => true]));

        $this->assertSame(['store_ids'], $payload['settings_changed'] ?? null);
    }

    public function testARestrictedRoleMayNotChangeAnAllStoreViewsPageWhereItIs(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('update');

        $result = $this->tool($this->createMock(ContentService::class), $writer, [1], false, [0])
            ->execute(['identifier' => 'sale', 'settings' => ['title' => 'Summer sale']]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('store view 0', json_encode($result->getContent()) ?: '');
    }

    public function testARestrictedRoleMayNotEmptyTheStoreViewsOfAnAllStoreViewsPage(): void
    {
        // F1: an empty assignment would drop store view 0 and take the page offline on every website.
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('update');

        $result = $this->tool($this->createMock(ContentService::class), $writer, [1], false, [0])->execute([
            'identifier' => 'sale',
            'settings' => ['store_ids' => [], 'title' => 'Summer sale'],
            'confirm' => true,
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('store view 0', json_encode($result->getContent()) ?: '');
    }

    /**
     * @param class-string<PageUpdate|BlockUpdate> $class
     * @dataProvider updateToolProvider
     */
    public function testTheSchemaNeedsAtLeastOneStoreView(string $class): void
    {
        $tool = new $class(
            $this->createMock(ContentLocator::class),
            $this->createMock(ContentService::class),
            $this->createMock(ComponentTree::class),
            $this->createMock(DraftEditor::class),
            $this->createMock(EntityWriter::class),
            new LiveSettings()
        );

        $properties = $tool->getInputSchema()['properties'] ?? null;
        $this->assertIsArray($properties);
        $this->assertSame(1, $properties['settings']['properties']['store_ids']['minItems'] ?? null);
    }

    /**
     * @return array<string, array{class-string<PageUpdate|BlockUpdate>}>
     */
    public static function updateToolProvider(): array
    {
        return ['page' => [PageUpdate::class], 'block' => [BlockUpdate::class]];
    }

    public function testACallAllowedOnlyByItsMoveMovesThePageBeforeSavingTheDraft(): void
    {
        // F2: the draft must not land on the All Store Views page before the page is in the role's store views.
        $order = [];
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->expects($this->once())->method('saveDraft')->willReturnCallback(
            static function () use (&$order): array {
                $order[] = 'draft';
                return ['version_id' => 2, 'version_uid' => 'v2', 'warnings' => []];
            }
        );
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('update')->willReturnCallback(
            static function () use (&$order): array {
                $order[] = 'settings';
                return ['store_ids'];
            }
        );

        $payload = $this->payload($this->tool($contentService, $writer, [1], false, [0], $this->editor())->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'confirm' => true,
        ]));

        $this->assertSame(['settings', 'draft'], $order);
        $this->assertSame(['store_ids'], $payload['settings_changed'] ?? null);
        $draft = $payload['draft'] ?? null;
        $this->assertIsArray($draft);
        $this->assertSame('v2', $draft['version_uid'] ?? null);
    }

    public function testAFailedMoveOfACallAllowedOnlyByItsMoveSavesNoDraft(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->expects($this->never())->method('saveDraft');
        $writer = $this->createMock(EntityWriter::class);
        $writer->method('update')->willThrowException(new LocalizedException(__('URL key already exists')));

        $this->expectException(LocalizedException::class);
        $this->tool($contentService, $writer, [1], false, [0], $this->editor())->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1], 'identifier' => 'sale-taken'],
            'confirm' => true,
        ]);
    }

    public function testARefusedDraftAfterTheMoveReportsTheAppliedSettings(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->method('saveDraft')->willThrowException(new ConflictException('Saved elsewhere'));
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('update')->willReturn(['store_ids']);

        $result = $this->tool($contentService, $writer, [1], false, [0], $this->editor())->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'confirm' => true,
        ]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertTrue($payload['settings_applied'] ?? null);
        $this->assertFalse($payload['draft_saved'] ?? null);
        $this->assertSame(409, $payload['status'] ?? null);
        $this->assertSame(['store_ids'], $payload['settings_changed'] ?? null);
    }

    public function testADraftRefusalKnownBeforeTheMoveWritesNothing(): void
    {
        // F4: a stale base_version_uid is refused before the page is moved, so nothing is written.
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->expects($this->once())->method('assertDraftSavable')
            ->with('cms_page', 7, ['content' => []], 'what-i-read')
            ->willThrowException(new ConflictException('Saved elsewhere'));
        $contentService->expects($this->never())->method('saveDraft');
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('update');

        $result = $this->tool($contentService, $writer, [1], false, [0], $this->editor())->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'base_version_uid' => 'what-i-read',
            'confirm' => true,
        ]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertSame(409, $payload['status'] ?? null);
        $this->assertArrayNotHasKey('settings_applied', $payload);
    }

    public function testInvalidContentKnownBeforeTheMoveWritesNothing(): void
    {
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->method('assertDraftSavable')
            ->willThrowException(new LocalizedException(__('The content was not saved: [x] Unknown component')));
        $contentService->expects($this->never())->method('saveDraft');
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('update');

        $this->expectException(LocalizedException::class);
        $this->tool($contentService, $writer, [1], false, [0], $this->editor())->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'confirm' => true,
        ]);
    }

    public function testAnUnrestrictedMoveKeepsTheDraftFirst(): void
    {
        $order = [];
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('getWorkingDocument')->willReturn(['content' => []]);
        $contentService->method('saveDraft')->willReturnCallback(static function () use (&$order): array {
            $order[] = 'draft';
            return ['version_id' => 2, 'version_uid' => 'v2', 'warnings' => []];
        });
        $writer = $this->createMock(EntityWriter::class);
        $writer->method('update')->willReturnCallback(static function () use (&$order): array {
            $order[] = 'settings';
            return ['store_ids'];
        });

        $contentService->expects($this->never())->method('assertDraftSavable');

        $this->tool($contentService, $writer, null, false, [0], $this->editor())->execute([
            'identifier' => 'sale',
            'operations' => [['op' => 'remove', 'uid' => 'x']],
            'settings' => ['store_ids' => [1]],
            'confirm' => true,
        ]);

        $this->assertSame(['draft', 'settings'], $order);
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
            new LiveSettings()
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
     * @param array $storeIds Store views the page is assigned to.
     * @param DraftEditor|null $editor
     * @return PageUpdate
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param list<int> $storeIds
     */
    private function tool(
        ContentService $contentService,
        EntityWriter $writer,
        ?array $allowedStoreIds = null,
        bool $active = false,
        array $storeIds = [1],
        ?DraftEditor $editor = null
    ): PageUpdate {
        $page = ['is_active' => $active, 'store_ids' => $storeIds] + self::PAGE;
        $guard = $this->storeScope($allowedStoreIds);
        $locator = $this->createMock(ContentLocator::class);
        // The locator's write check, over the real guard.
        $locator->method('locateForWrite')->willReturnCallback(
            static function (string $type, array $arguments, ?array $newStoreIds = null) use ($guard, $page): array {
                $guard->assertChangeable('CMS page #7', $page['store_ids'], $newStoreIds);
                return $page;
            }
        );
        $locator->method('changesOnlyAfterReassignment')->willReturnCallback(
            static fn (array $entity, ?array $newStoreIds): bool
                => $guard->changesOnlyAfterReassignment($page['store_ids'], $newStoreIds)
        );
        return new PageUpdate(
            $locator,
            $contentService,
            $this->createMock(ComponentTree::class),
            $editor ?? $this->createMock(DraftEditor::class),
            $writer,
            new LiveSettings()
        );
    }

    /**
     * Draft editor that applies every operation without changes.
     *
     * @return DraftEditor
     */
    private function editor(): DraftEditor
    {
        $editor = $this->createMock(DraftEditor::class);
        $editor->method('apply')->willReturn(['document' => ['content' => []], 'applied' => [], 'dropped' => []]);
        return $editor;
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
