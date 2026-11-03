<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\Description;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block\BlockList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block\BlockUpdate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageUpdate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuUpdate;
use PHPUnit\Framework\TestCase;

/**
 * T-22: there is no delete tool for pages, blocks and menus, and the descriptions say so.
 */
class AdminOnlyDeleteNoteTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<ToolInterface>, 1: string}>
     */
    public static function toolProvider(): array
    {
        return [
            'page list' => [PageList::class, 'Deleting a CMS page is not possible over MCP'],
            'page update' => [PageUpdate::class, 'Deleting a CMS page is not possible over MCP'],
            'block list' => [BlockList::class, 'Deleting a CMS block is not possible over MCP'],
            'block update' => [BlockUpdate::class, 'Deleting a CMS block is not possible over MCP'],
            'menu list' => [MenuList::class, 'Deleting a menu is not possible over MCP'],
            'menu update' => [MenuUpdate::class, 'Deleting a menu is not possible over MCP'],
        ];
    }

    /**
     * @dataProvider toolProvider
     * @param class-string<ToolInterface> $class
     * @param string $note
     */
    public function testTheDescriptionSaysDeletionIsAdminOnly(string $class, string $note): void
    {
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $description = $tool->getDescription();
        $this->assertStringContainsString($note, $description);
        $this->assertStringContainsString('do not deactivate, empty or unassign it instead', $description);
        $this->assertStringNotContainsString('%', $description);
    }
}
