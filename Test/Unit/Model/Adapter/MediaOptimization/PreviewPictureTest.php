<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\MediaOptimization;

use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Hyva\MediaOptimization\Model\Config\GeneralConfig;
use Hyva\MediaOptimization\Model\Config\HtmlCssReplacementConfig;
use Hyva\MediaOptimization\Model\Config\PostHocConfig;
use Hyva\MediaOptimization\Service\Cleaner;
use Hyva\MediaOptimization\Service\ImagePathResolver;
use Hyva\MediaOptimization\Service\Resize\EnginePool as ResizeEnginePool;
use Hyva\MediaOptimization\Service\Resize\ResizeEngineInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Adapter\MediaOptimization\MediaOptimizationGateway;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PreviewPictureTest extends TestCase
{
    use OptionalServicesStub;

    public function testOnlyExistingVariantsAreUsedAndNothingIsWritten(): void
    {
        // Existing: the original, its 1x resize (300x200) and the WebP of that resize. 2x/3x and AVIF are missing.
        $gateway = $this->gateway(
            ['wysiwyg/hero.jpg', 'resized/300x200/1x/hero.jpg', 'converted/resized/300x200/1x/hero.webp']
        );
        $preview = $gateway->previewPicture([['path' => 'wysiwyg/hero.jpg', 'width' => 300]], ['alt' => 'Hero']);

        $this->assertSame([
            ['type' => 'image/webp', 'media' => null,
                'srcset' => 'https://shop.test/media/converted/resized/300x200/1x/hero.webp 1x'],
            ['type' => 'image/jpeg', 'media' => null,
                'srcset' => 'https://shop.test/media/resized/300x200/1x/hero.jpg 1x'],
        ], $preview['sources']);
        $this->assertCount(7, $preview['missing'], '2x and 3x resizes, their WebP, and AVIF for all three densities');
        $this->assertStringContainsString('<img src="https://shop.test/media/resized/300x200/1x/hero.jpg"', $preview['html']);
        $this->assertStringContainsString('alt="Hero"', $preview['html']);
    }

    public function testWithoutDimensionsTheOriginalIsServedAs1x(): void
    {
        $preview = $this->gateway(['wysiwyg/hero.jpg', 'converted/wysiwyg/hero.webp'])
            ->previewPicture([['path' => 'wysiwyg/hero.jpg']]);

        $this->assertSame([
            ['type' => 'image/webp', 'media' => null,
                'srcset' => 'https://shop.test/media/converted/wysiwyg/hero.webp 1x'],
            ['type' => 'image/jpeg', 'media' => null, 'srcset' => 'https://shop.test/media/wysiwyg/hero.jpg 1x'],
        ], $preview['sources']);
        $this->assertSame([['path' => 'converted/wysiwyg/hero.avif', 'source' => 'wysiwyg/hero.jpg',
            'kind' => 'converted', 'density' => '1x', 'format' => 'avif']], $preview['missing']);
        $this->assertStringContainsString(
            '<img src="https://shop.test/media/wysiwyg/hero.jpg" width="1200" height="800"',
            $preview['html']
        );
    }

    public function testDimensionsWithoutAResizeEngineFallBackToTheOriginal(): void
    {
        // The media root itself "exists" (isExist('') is true), like on a real filesystem.
        $preview = $this->gateway(['', 'wysiwyg/hero.jpg'], false)
            ->previewPicture([['path' => 'wysiwyg/hero.jpg', 'width' => 300]]);

        $this->assertSame([], $preview['sources']);
        $this->assertSame([], $preview['missing']);
        $this->assertStringContainsString(
            '<img src="https://shop.test/media/wysiwyg/hero.jpg" width="300"',
            $preview['html']
        );
    }

    /**
     * Gateway over a media folder holding only the given files.
     *
     * @param list<string> $existing
     * @param bool $withEngine
     * @return MediaOptimizationGateway
     */
    private function gateway(array $existing, bool $withEngine = true): MediaOptimizationGateway
    {
        $reader = $this->createMock(ReadInterface::class);
        $reader->method('isExist')->willReturnCallback(static fn (string $p): bool => in_array($p, $existing, true));
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($reader);
        $filesystem->expects($this->never())->method('getDirectoryWrite');

        $resolver = $this->createMock(ImagePathResolver::class);
        $resolver->method('resolveResizedPath')->willReturnCallback(
            static fn (string $path, string $size, string $density): string => 'resized/' . $size . '/' . $density
                . '/' . basename($path)
        );
        $resolver->method('resolveConvertedPath')->willReturnCallback(
            static fn (string $path, string $format): string => 'converted/' . dirname($path) . '/'
                . pathinfo($path, PATHINFO_FILENAME) . '.' . $format
        );
        $config = $this->createMock(GeneralConfig::class);
        $config->method('getResizeEngine')->willReturn('gd');
        $config->method('isRetinaEnabled')->willReturn(true);
        $config->method('getOutputFormats')->willReturn(['webp', 'avif']);
        $engine = $this->createMock(ResizeEngineInterface::class);
        $engine->method('getCurrentSize')->willReturn(['width' => 1200, 'height' => 800]);
        $engines = $this->createMock(ResizeEnginePool::class);
        $engines->method('getEngineByCode')->willReturn($withEngine ? $engine : null);
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://shop.test/media/');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $cleaner = $this->createMock(Cleaner::class);
        $cleaner->expects($this->never())->method($this->anything());

        return new MediaOptimizationGateway(
            $this->optionalServices([
                GeneralConfig::class => $config,
                HtmlCssReplacementConfig::class => $this->createMock(HtmlCssReplacementConfig::class),
                PostHocConfig::class => $this->createMock(PostHocConfig::class),
                ImagePathResolver::class => $resolver,
                Cleaner::class => $cleaner,
                ResizeEnginePool::class => $engines,
            ]),
            $stores,
            $filesystem,
            $this->createMock(LoggerInterface::class)
        );
    }
}
