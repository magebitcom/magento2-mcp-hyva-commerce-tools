<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Asset;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetMove;
use PHPUnit\Framework\MockObject\MockObject;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use PHPUnit\Framework\TestCase;

class AssetMoveConfirmTest extends TestCase
{
    use ContentScopeStub;
    use ToolPayloadTrait;

    /**
     * @var MediaGalleryGatewayInterface&MockObject
     */
    private MediaGalleryGatewayInterface&MockObject $gallery;

    /**
     * @var ImageUsageIndex&MockObject
     */
    private ImageUsageIndex&MockObject $usageIndex;

    /**
     * @var AssetMove
     */
    private AssetMove $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $featureChecker = $this->createMock(FeatureCheckerInterface::class);
        $featureChecker->method('isAvailable')->willReturn(true);
        $this->gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $this->gallery->method('getByIds')->willReturn([
            $this->asset(1, 'wysiwyg/a.jpg'),
            $this->asset(2, 'wysiwyg/b.jpg'),
            $this->asset(3, 'wysiwyg/c.jpg'),
        ]);
        $this->usageIndex = $this->createMock(ImageUsageIndex::class);
        $this->tool = new AssetMove(
            $featureChecker,
            $this->gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->usageIndex,
            $this->createMock(CallerContext::class),
            $this->contentScope(null)
        );
    }

    /**
     * @return void
     */
    public function testWithoutConfirmNothingIsMovedAndUsageIsBatched(): void
    {
        $this->gallery->expects($this->never())->method('move');
        $this->gallery->expects($this->once())->method('magentoUsage')->with([1, 2, 3])->willReturn([]);
        $this->usageIndex->expects($this->never())->method('usagesOf');
        $this->usageIndex->expects($this->once())->method('usagesOfMany')
            ->with(['wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'wysiwyg/c.jpg'])
            ->willReturn(['wysiwyg/b.jpg' => [$this->usage()]]);

        $result = $this->tool->execute(['ids' => [1, 2, 3], 'target_folder' => 'wysiwyg/campaigns']);

        $this->assertTrue($this->payloadAt($result, 'confirmation_required'));
        $this->assertSame('wysiwyg/campaigns', $this->payloadAt($result, 'affected.target_folder'));
        $this->assertSame([], $this->payloadAt($result, 'affected.assets.0.used_in.hyva_and_catalog'));
        $this->assertCount(1, $this->arrayAt($result, 'affected.assets.1.used_in.hyva_and_catalog'));
    }

    /**
     * @return void
     */
    public function testWithConfirmAssetsAreMoved(): void
    {
        $this->gallery->expects($this->never())->method('magentoUsage');
        $this->gallery->expects($this->once())->method('move')->with([1, 2, 3], 'wysiwyg/campaigns')
            ->willReturn(['moved' => 3, 'skipped' => 0, 'skipped_files' => []]);

        $result = $this->tool->execute(
            ['ids' => [1, 2, 3], 'target_folder' => 'wysiwyg/campaigns', 'confirm' => true]
        );

        $this->assertSame(3, $this->payloadAt($result, 'moved'));
    }

    /**
     * @param int $id
     * @param string $path
     * @return array
     * @phpstan-return array{id: int, path: string, title: string, content_type: string, width: int, height: int,
     *     size: int, updated_at: string}
     */
    private function asset(int $id, string $path): array
    {
        return ['id' => $id, 'path' => $path, 'title' => '', 'content_type' => 'image/jpeg', 'width' => 1,
            'height' => 1, 'size' => 1, 'updated_at' => ''];
    }

    /**
     * @return array
     * @phpstan-return array{source: string, entity_type: string, id: int, identifier: string, edition: string,
     *     uid: string|null, component: string|null, field: string|null, alt: string|null, has_alt: bool}
     */
    private function usage(): array
    {
        return ['source' => 'hyva_cms', 'entity_type' => 'page', 'id' => 1, 'identifier' => 'home',
            'edition' => 'draft', 'uid' => null, 'component' => null, 'field' => null, 'alt' => null,
            'has_alt' => false];
    }
}
