<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Folder;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Folder\FolderCreate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FolderCreateTest extends TestCase
{
    use ToolPayloadTrait;

    /**
     * @var MediaGalleryGatewayInterface&MockObject
     */
    private MediaGalleryGatewayInterface&MockObject $gallery;

    /**
     * @var MediaFileInfo&MockObject
     */
    private MediaFileInfo&MockObject $fileInfo;

    /**
     * @var FolderCreate
     */
    private FolderCreate $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $this->gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $this->fileInfo = $this->createMock(MediaFileInfo::class);
        $this->tool = new FolderCreate(
            $featureChecker,
            $this->gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageUsageIndex::class),
            $this->createMock(CallerContext::class),
            $this->fileInfo
        );
    }

    /**
     * @return void
     */
    public function testAFolderInsideMediaIsCreated(): void
    {
        $this->fileInfo->expects($this->once())->method('resolvesOutsideMedia')
            ->with('wysiwyg/campaigns/spring')->willReturn(false);
        $this->gallery->expects($this->once())->method('createFolder')->with('wysiwyg/campaigns/spring');

        $result = $this->tool->execute(['path' => '/wysiwyg/campaigns/spring/']);

        $this->assertFalse($result->isError());
        $this->assertTrue($this->payloadAt($result, 'created'));
        $this->assertSame('wysiwyg/campaigns/spring', $this->payloadAt($result, 'path'));
    }

    /**
     * T-171: a folder whose nearest existing parent a symbolic link takes outside pub/media is not created.
     *
     * @return void
     */
    public function testAFolderUnderALinkOutsideMediaIsRefused(): void
    {
        $this->fileInfo->method('resolvesOutsideMedia')
            ->willReturnCallback(static fn (string $path): bool => str_starts_with($path, 'wysiwyg/linked'));
        $this->gallery->expects($this->never())->method('createFolder');

        $result = $this->tool->execute(['path' => 'wysiwyg/linked/new']);

        $this->assertTrue($result->isError());
        $this->assertSame(
            'Folder "wysiwyg/linked/new" resolves outside pub/media through a symbolic link; no folder was created.',
            $this->payloadAt($result, 'error')
        );
        $this->assertSame(['wysiwyg/linked/new'], $this->payloadAt($result, 'paths_outside_media'));
    }

    /**
     * The lexical path check runs first, so an invalid path never reaches the file system.
     *
     * @return void
     */
    public function testAnInvalidPathIsRefusedBeforeTheLinkCheck(): void
    {
        $this->fileInfo->expects($this->never())->method('resolvesOutsideMedia');
        $this->gallery->expects($this->never())->method('createFolder');

        $result = $this->tool->execute(['path' => 'wysiwyg/../etc']);

        $this->assertTrue($result->isError());
    }
}
