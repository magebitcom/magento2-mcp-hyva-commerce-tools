<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\MediaOptimization;

use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magebit\Mcp\Api\LoggerInterface;
use Hyva\MediaOptimization\Model\Config\GeneralConfig;
use Hyva\MediaOptimization\Model\Config\HtmlCssReplacementConfig;
use Hyva\MediaOptimization\Model\Config\PostHocConfig;
use Hyva\MediaOptimization\Service\Resize\EnginePool as ResizeEnginePool;
use Magento\Store\Model\StoreManagerInterface;
use Hyva\MediaOptimization\Service\Cleaner;
use Hyva\MediaOptimization\Service\ImagePathResolver;
use Magebit\McpHyvaCommerceTools\Model\Adapter\MediaOptimization\MediaOptimizationGateway;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MediaOptimizationGatewayTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * @var list<string>
     */
    private array $deleted = [];

    /**
     * @var Cleaner&MockObject
     */
    private Cleaner $cleaner;

    /**
     * @var MediaOptimizationGateway
     */
    private MediaOptimizationGateway $gateway;

    protected function setUp(): void
    {
        $resolver = $this->createMock(ImagePathResolver::class);
        $resolver->method('resolveResizedDirectory')->willReturn('resized/0f3c');
        // Converted variants of a media-root file; the "jpg" mapping points back at the original on purpose.
        $resolver->method('resolveConvertedPath')->willReturnCallback(
            static fn (string $path, string $format): string => $format === 'jpg'
                ? $path
                : 'converted/' . pathinfo($path, PATHINFO_FILENAME) . '.' . $format
        );
        $writer = $this->createMock(WriteInterface::class);
        $writer->method('isDirectory')->willReturn(true);
        $writer->method('isFile')->willReturn(true);
        $writer->method('delete')->willReturnCallback(function (string $path): bool {
            $this->deleted[] = $path;
            return true;
        });
        $reader = $this->createMock(ReadInterface::class);
        $reader->method('isDirectory')->willReturn(false);
        $reader->method('isFile')->willReturn(false);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($writer);
        $filesystem->method('getDirectoryRead')->willReturn($reader);
        $this->cleaner = $this->createMock(Cleaner::class);
        $this->cleaner->expects($this->never())->method('clearConvertedMediaByPath');

        $this->gateway = new MediaOptimizationGateway(
            $this->optionalServices([
                GeneralConfig::class => $this->createMock(GeneralConfig::class),
                HtmlCssReplacementConfig::class => $this->createMock(HtmlCssReplacementConfig::class),
                PostHocConfig::class => $this->createMock(PostHocConfig::class),
                ImagePathResolver::class => $resolver,
                Cleaner::class => $this->cleaner,
                ResizeEnginePool::class => $this->createMock(ResizeEnginePool::class),
            ]),
            $this->createMock(StoreManagerInterface::class),
            $filesystem,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testClearOnlyDeletesConvertedPathsAndNeverTheOriginal(): void
    {
        $this->cleaner->expects($this->once())->method('clearResizedMediaByPath')->with('hero.jpg');
        $this->gateway->clear('hero.jpg');

        $this->assertNotContains('hero.jpg', $this->deleted);
        $this->assertContains('converted/resized/0f3c', $this->deleted);
        $this->assertContains('converted/hero.webp', $this->deleted);
        $this->assertContains('converted/hero.avif', $this->deleted);
        foreach ($this->deleted as $path) {
            $this->assertStringStartsWith('converted/', $path);
        }
    }

    public function testResizedModeLeavesConvertedFiles(): void
    {
        $this->cleaner->expects($this->once())->method('clearResizedMediaByPath');
        $this->gateway->clear('hero.jpg', 'resized');
        $this->assertSame([], $this->deleted);
    }

    public function testConvertedModeLeavesResizedFiles(): void
    {
        $this->cleaner->expects($this->never())->method('clearResizedMediaByPath');
        $this->gateway->clear('hero.jpg', 'converted');
        $this->assertNotSame([], $this->deleted);
    }
}
