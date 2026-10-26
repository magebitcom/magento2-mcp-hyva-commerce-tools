<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageChecker;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use PHPUnit\Framework\TestCase;

/**
 * No missing_<format> issue for an image that already is that format (Hyvä's ImageConverter skips it).
 */
class ImageCheckerFormatsTest extends TestCase
{
    public function testAWebpImageNeedsNoWebpVariant(): void
    {
        $this->assertSame(['missing_avif'], $this->variantIssueCodes('a.webp'));
    }

    public function testAJpgImageNeedsBothVariants(): void
    {
        $this->assertSame(['missing_webp', 'missing_avif'], $this->variantIssueCodes('a.jpg'));
    }

    /**
     * Codes of the missing_<format> issues for a file whose variants exist only as resized copies.
     *
     * @param string $path
     * @return array
     * @phpstan-return list<string>
     */
    private function variantIssueCodes(string $path): array
    {
        $info = $this->createMock(MediaFileInfo::class);
        $info->method('describe')->willReturn([
            'path' => $path,
            'exists' => true,
            'size' => 1024,
            'width' => 10,
            'height' => 10,
            'mime' => 'image/jpeg',
            'modified_at' => null,
            'extension' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ]);
        $checker = new ImageChecker($info, $this->createMock(LoggerInterface::class));
        $result = $checker->check($path, [], [
            'variants' => ['resized' => ['x'], 'converted' => [], 'formats' => []],
            'output_formats' => ['webp', 'avif'],
        ]);
        return array_values(array_filter(
            array_column($result['issues'], 'code'),
            static fn (string $code): bool => str_starts_with($code, 'missing_') && $code !== 'missing_alt'
        ));
    }
}
