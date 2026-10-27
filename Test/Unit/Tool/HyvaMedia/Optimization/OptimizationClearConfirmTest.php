<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Optimization;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaOptimizationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Optimization\OptimizationClear;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OptimizationClearConfirmTest extends TestCase
{
    use ToolPayloadTrait;

    /**
     * @var MediaOptimizationGatewayInterface&MockObject
     */
    private MediaOptimizationGatewayInterface&MockObject $gateway;

    /**
     * @var OptimizationClear
     */
    private OptimizationClear $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $this->gateway = $this->createMock(MediaOptimizationGatewayInterface::class);
        $this->gateway->method('countAllGenerated')->willReturn(['resized' => 40, 'converted' => 2]);
        $editor = $this->createMock(ImageContentEditor::class);
        $editor->method('normalize')->willReturnArgument(0);
        $this->tool = new OptimizationClear($features, $this->gateway, $editor);
    }

    public function testAllImagesWithoutConfirmClearsNothing(): void
    {
        $this->gateway->expects($this->never())->method('clearAll');
        $this->gateway->expects($this->never())->method('clear');

        $result = $this->tool->execute(['all_images' => true, 'mode' => 'resized']);

        $this->assertFalse($result->isError());
        $this->assertTrue($this->payloadAt($result, 'confirmation_required'));
        $this->assertSame('clear_all_optimized_images', $this->payloadAt($result, 'action'));
        $this->assertSame(['resized' => 40, 'converted' => 2, 'mode' => 'resized'], $this->payloadAt($result, 'affected.0'));
    }

    public function testConfirmClearsAllImages(): void
    {
        $this->gateway->expects($this->once())->method('clearAll')->with('resized')->willReturn(40);

        $result = $this->tool->execute(['all_images' => true, 'mode' => 'resized', 'confirm' => true]);

        $this->assertFalse($result->isError());
        $this->assertSame('all_images', $this->payloadAt($result, 'cleared'));
        $this->assertSame(40, $this->payloadAt($result, 'files_removed'));
    }

    public function testOnePathNeedsNoConfirm(): void
    {
        $this->gateway->expects($this->never())->method('clearAll');
        $this->gateway->expects($this->once())->method('clear')->with('wysiwyg/a.jpg', 'all')->willReturn(3);

        $result = $this->tool->execute(['path' => 'wysiwyg/a.jpg']);

        $this->assertFalse($result->isError());
        $this->assertSame(3, $this->payloadAt($result, 'files_removed'));
    }
}
