<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Trash;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Trash\TrashRestore;
use PHPUnit\Framework\TestCase;

class TrashRestoreTest extends TestCase
{
    use ToolPayloadTrait;

    /**
     * A folder at the original path blocks the restore, as Hyvä's isExist() check would skip the move but still
     * drop the trash row.
     *
     * @return void
     */
    public function testAFolderAtTheOriginalPathBlocksTheRestore(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('trashList')->willReturn(['items' => [
            ['id' => 1, 'path' => 'wysiwyg/a.jpg'],
            ['id' => 2, 'path' => 'wysiwyg/b.jpg'],
        ], 'total' => 2, 'retention_days' => 30]);
        $gallery->method('fileExists')->willReturn(false);
        $gallery->method('pathExists')->willReturnCallback(static fn (string $path): bool => $path === 'wysiwyg/b.jpg');
        $gallery->expects($this->once())->method('restore')->with([1])->willReturn(1);

        $tool = new TrashRestore(
            $featureChecker,
            $gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageUsageIndex::class),
            $this->createMock(CallerContext::class)
        );
        $result = $tool->execute(['trash_ids' => [1, 2]]);

        $this->assertSame(1, $this->payloadAt($result, 'restored'));
        $this->assertSame(2, $this->payloadAt($result, 'not_restored.0.trash_id'));
    }
}
