<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageUpdate;
use PHPUnit\Framework\TestCase;

class PageUpdateConfirmTest extends TestCase
{
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

    /**
     * Page update over an inactive page.
     *
     * @param ContentService $contentService
     * @param EntityWriter $writer
     * @return PageUpdate
     */
    private function tool(ContentService $contentService, EntityWriter $writer): PageUpdate
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locate')->willReturn(self::PAGE);
        return new PageUpdate(
            $locator,
            $contentService,
            $this->createMock(ComponentTree::class),
            $this->createMock(DraftEditor::class),
            $writer,
            new LiveSettings()
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
