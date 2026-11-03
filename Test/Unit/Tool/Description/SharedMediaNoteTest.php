<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\Description;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\SharedMedia;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetMove;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetSearch;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetTrash;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Sync\SyncStatus;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Trash\TrashList;
use PHPUnit\Framework\TestCase;

/**
 * T-36: media tools say that media files have no store scope.
 */
class SharedMediaNoteTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function toolProvider(): array
    {
        return [
            'asset search' => [AssetSearch::class],
            'asset move' => [AssetMove::class],
            'asset trash' => [AssetTrash::class],
            'trash list' => [TrashList::class],
            'sync status' => [SyncStatus::class],
            'image list' => [ImageList::class],
        ];
    }

    /**
     * @dataProvider toolProvider
     * @param class-string $class
     */
    public function testTheDescriptionSaysMediaFilesAreShared(string $class): void
    {
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->assertInstanceOf(ToolInterface::class, $tool);
        $this->assertStringContainsString(SharedMedia::DESCRIPTION_NOTE, $tool->getDescription());
    }
}
