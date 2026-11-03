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
 * T-22: the page, block and menu list / update descriptions name the delete tool.
 */
class DeleteToolNoteTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<ToolInterface>, 1: string}>
     */
    public static function toolProvider(): array
    {
        return [
            'page list' => [PageList::class, 'To delete a CMS page use hyva_cms_page_delete'],
            'page update' => [PageUpdate::class, 'To delete a CMS page use hyva_cms_page_delete'],
            'block list' => [BlockList::class, 'To delete a CMS block use hyva_cms_block_delete'],
            'block update' => [BlockUpdate::class, 'To delete a CMS block use hyva_cms_block_delete'],
            'menu list' => [MenuList::class, 'To delete a menu use hyva_menu_delete'],
            'menu update' => [MenuUpdate::class, 'To delete a menu use hyva_menu_delete'],
        ];
    }

    /**
     * @dataProvider toolProvider
     * @param class-string<ToolInterface> $class
     * @param string $note
     */
    public function testTheDescriptionNamesTheDeleteTool(string $class, string $note): void
    {
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $description = $tool->getDescription();
        $this->assertStringContainsString($note, $description);
        $this->assertStringContainsString('Do not deactivate, empty or unassign it as a substitute', $description);
        $this->assertStringNotContainsString('%', $description);
    }
}
