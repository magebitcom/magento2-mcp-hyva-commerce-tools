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
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetTrash;
use PHPUnit\Framework\MockObject\MockObject;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use PHPUnit\Framework\TestCase;

class AssetTrashConfirmTest extends TestCase
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
     * @var AssetTrash
     */
    private AssetTrash $tool;

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
        $this->tool = new AssetTrash(
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
    public function testWithoutConfirmNothingIsTrashedAndUsageIsBatched(): void
    {
        $this->gallery->expects($this->never())->method('trash');
        $this->gallery->expects($this->once())->method('magentoUsage')->with([1, 2, 3])
            ->willReturn([3 => [['entity_type' => 'cms_page', 'entity_id' => '7', 'field' => 'content']]]);
        $this->usageIndex->expects($this->never())->method('usagesOf');
        $this->usageIndex->expects($this->once())->method('usagesOfMany')
            ->with(['wysiwyg/a.jpg', 'wysiwyg/b.jpg', 'wysiwyg/c.jpg'])
            ->willReturn(['wysiwyg/a.jpg' => [$this->usage()], 'wysiwyg/b.jpg' => [], 'wysiwyg/c.jpg' => []]);

        $result = $this->tool->execute(['ids' => [1, 2, 3]]);

        $this->assertTrue($this->payloadAt($result, 'confirmation_required'));
        $this->assertSame(2, $this->payloadAt($result, 'affected.in_use'));
        $this->assertCount(1, $this->arrayAt($result, 'affected.assets.0.used_in.hyva_and_catalog'));
        $this->assertSame([], $this->payloadAt($result, 'affected.assets.1.used_in.magento_tracked'));
        $this->assertCount(1, $this->arrayAt($result, 'affected.assets.2.used_in.magento_tracked'));
    }

    /**
     * @return void
     */
    public function testWithConfirmAssetsAreTrashed(): void
    {
        $this->gallery->method('magentoUsage')->willReturn([]);
        $this->usageIndex->method('usagesOfMany')->willReturn([]);
        $this->gallery->expects($this->once())->method('trash')->with([1, 2, 3])->willReturn(3);

        $result = $this->tool->execute(['ids' => [1, 2, 3], 'confirm' => true]);

        $this->assertSame(3, $this->payloadAt($result, 'trashed'));
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
