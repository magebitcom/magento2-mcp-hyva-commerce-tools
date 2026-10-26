<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\MediaGallery;

use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Tool\MediaGallery\AbstractMediaGalleryTool;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Folder\FolderCreate;
use PHPUnit\Framework\TestCase;

class FolderPathTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'nested folder' => ['wysiwyg/campaigns/black-friday', true],
            'slashes trimmed' => ['/wysiwyg/a/', true],
            'traversal' => ['wysiwyg/../etc', false],
            'backslash' => ['wysiwyg\\x', false],
            'outside wysiwyg' => ['catalog/product', false],
            'prefix lookalike' => ['wysiwygx/a', false],
            'empty' => ['/', false],
            'excluded' => ['wysiwyg/excluded', false],
        ];
    }

    /**
     * @dataProvider paths
     * @param string $path
     * @param bool $allowed
     * @return void
     */
    public function testFolderError(string $path, bool $allowed): void
    {
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('isExcluded')->willReturnCallback(
            static fn (string $folder): bool => str_starts_with($folder, 'wysiwyg/excluded')
        );
        $tool = new FolderCreate(
            $this->createMock(FeatureCheckerInterface::class),
            $gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->createMock(ImageUsageIndex::class),
            $this->createMock(CallerContext::class)
        );
        $method = new \ReflectionMethod(AbstractMediaGalleryTool::class, 'folderError');

        $this->assertSame($allowed, $method->invoke($tool, $path) === null);
    }
}
