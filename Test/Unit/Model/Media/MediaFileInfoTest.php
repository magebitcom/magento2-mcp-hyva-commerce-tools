<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\PathValidator;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\File\ReadFactory;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

/**
 * M1 (review lap 3): folder and file checks never throw on a path Magento's path validator refuses, so the
 * absolute pub/media path never reaches the client.
 */
class MediaFileInfoTest extends TestCase
{
    /**
     * @var string
     */
    private string $media = '';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/mhp1099-media-' . bin2hex(random_bytes(4));
        $this->media = $root . '/media';
        mkdir($this->media . '/wysiwyg/banners', 0777, true);
        file_put_contents($this->media . '/wysiwyg/banners/a.png', 'x');
    }

    protected function tearDown(): void
    {
        $root = dirname($this->media);
        unlink($this->media . '/wysiwyg/banners/a.png');
        rmdir($this->media . '/wysiwyg/banners');
        rmdir($this->media . '/wysiwyg');
        rmdir($this->media);
        rmdir($root);
    }

    public function testAnExistingFolderIsFound(): void
    {
        $info = $this->info();

        $this->assertTrue($info->folderExists('wysiwyg'));
        $this->assertTrue($info->folderExists('/wysiwyg/banners/'));
        $this->assertFalse($info->folderExists('wysiwyg/missing'));
        $this->assertFalse($info->folderExists(''));
        $this->assertFalse($info->folderExists('/'));
    }

    /**
     * @dataProvider refusedFolders
     */
    public function testAFolderThePathValidatorRefusesIsMissing(string $folder): void
    {
        $this->assertFalse($this->info()->folderExists($folder));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedFolders(): array
    {
        return [
            'parent' => ['../x'],
            'nested parent' => ['wysiwyg/../../etc'],
            'leading dash' => ['-x'],
            'space dash' => ['wysiwyg/a -b'],
            'tab' => ["wysiwyg/a\tb"],
            'newline' => ["wysiwyg/a\nb"],
            'null byte' => ["wysiwyg\0"],
        ];
    }

    public function testAFileThePathValidatorRefusesIsMissing(): void
    {
        $info = $this->info();

        $this->assertTrue($info->exists('wysiwyg/banners/a.png'));
        $this->assertFalse($info->exists('-x.png'));
        $this->assertFalse($info->exists('wysiwyg/a -b.png'));
        $this->assertFalse($info->exists('../x.png'));
        $this->assertNull($info->size('-x.png'));
    }

    public function testADirectoryThatThrowsNeverLeaksItsMessage(): void
    {
        $read = $this->createMock(ReadInterface::class);
        $read->method('isDirectory')->willThrowException(
            new ValidatorException(new Phrase('Path "-x" cannot be used with directory "/var/www/pub/media/"'))
        );
        $read->method('isFile')->willThrowException(new \RuntimeException('/var/www/pub/media/'));
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($read);
        $info = new MediaFileInfo($filesystem, $this->createMock(LoggerInterface::class), new File());

        $this->assertFalse($info->folderExists('-x'));
        $this->assertFalse($info->exists('-x.png'));
    }

    /**
     * T-143: a symbolic link under pub/media that points outside it is not inside the media directory, whatever
     * Magento's lexical path validator says; a link to a media folder and paths that do not exist yet are judged by
     * where they really lead.
     *
     * @return void
     */
    public function testALinkOutOfMediaResolvesOutside(): void
    {
        $outside = dirname($this->media) . '/outside';
        mkdir($outside);
        symlink($outside, $this->media . '/wysiwyg/linked');
        symlink($this->media . '/wysiwyg/banners', $this->media . '/wysiwyg/alias');
        try {
            $info = $this->info();

            $this->assertTrue($info->folderExists('wysiwyg/linked'));
            $this->assertTrue($info->resolvesOutsideMedia('wysiwyg/linked'));
            $this->assertTrue($info->resolvesOutsideMedia('wysiwyg/linked/new/deeper'));
            $this->assertTrue($info->resolvesOutsideMedia('/wysiwyg/linked/a.png'));
            $this->assertFalse($info->resolvesOutsideMedia('wysiwyg/alias'));
            $this->assertFalse($info->resolvesOutsideMedia('wysiwyg/banners/a.png'));
            $this->assertFalse($info->resolvesOutsideMedia('wysiwyg/new-folder/x'));
            $this->assertFalse($info->resolvesOutsideMedia('wysiwyg'));
            $this->assertFalse($info->resolvesOutsideMedia('-x'));
        } finally {
            unlink($this->media . '/wysiwyg/alias');
            unlink($this->media . '/wysiwyg/linked');
            rmdir($outside);
        }
    }

    /**
     * MediaFileInfo over a real Magento media directory (real file driver and path validator) in a temp folder.
     *
     * @return MediaFileInfo
     */
    private function info(): MediaFileInfo
    {
        $driver = new File();
        $read = new Read(
            $this->createMock(ReadFactory::class),
            $driver,
            $this->media,
            new PathValidator($driver)
        );
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($read);
        return new MediaFileInfo($filesystem, $this->createMock(LoggerInterface::class), $driver);
    }
}
