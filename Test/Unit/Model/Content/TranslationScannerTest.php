<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\TranslationScanner;
use PHPUnit\Framework\TestCase;

class TranslationScannerTest extends TestCase
{
    /**
     * @var TranslationScanner
     */
    private TranslationScanner $scanner;

    protected function setUp(): void
    {
        $definitions = [
            'heading' => ['content' => [
                'text' => ['type' => 'text', 'translate' => true],
                'tag' => ['type' => 'select'],
            ]],
            'banner' => ['content' => [
                'image' => ['type' => 'image'],
                'link' => ['type' => 'link'],
                'note' => ['type' => 'text'],
            ]],
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponent')->willReturnCallback(
            static fn (string $name): ?array => $definitions[$name] ?? null
        );
        $this->scanner = new TranslationScanner(new ComponentTree($provider));
    }

    public function testTextsFollowTheEditorRules(): void
    {
        $texts = $this->scanner->texts($this->document());
        $this->assertSame(
            ['h1.text', 'b1.image.alt', 'b1.link.label'],
            array_map(static fn (array $t): string => $t['uid'] . '.' . $t['field'], $texts),
            'translate:true fields, image alt and link label; not untranslatable or empty fields'
        );
        $this->assertSame([1 => 'Herbstverkauf'], $texts[0]['translations']);
    }

    public function testStatusCountsPerStore(): void
    {
        $status = $this->scanner->status($this->document(), [1, 2]);
        $this->assertSame(3, $status['texts_total']);
        $this->assertSame(['translated' => 1, 'missing' => 2], $status['stores'][1]);
        $this->assertSame(['translated' => 0, 'missing' => 3], $status['stores'][2]);
        $this->assertSame([1, 2], $status['missing_sample'][1]['missing_store_ids']);
    }

    public function testApplyMergesIntoExistingTranslations(): void
    {
        $document = $this->scanner->apply($this->document(), [
            ['uid' => 'h1', 'field' => 'text', 'store_id' => 2, 'translation' => 'Soldes'],
            ['uid' => 'b1', 'field' => 'image.alt', 'store_id' => 1, 'translation' => 'Mantel'],
        ]);
        $texts = $this->scanner->texts($document);
        $this->assertSame([1 => 'Herbstverkauf', 2 => 'Soldes'], $texts[0]['translations']);
        $this->assertSame([1 => 'Mantel'], $texts[1]['translations']);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return ['uid' => 'doc', 'version' => 1, 'content' => [
            'h1' => ['uid' => 'h1', 'component' => 'heading', 'text' => 'Autumn sale', 'tag' => 'h1',
                'translations' => ['text' => [1 => 'Herbstverkauf']]],
            'b1' => ['uid' => 'b1', 'component' => 'banner', 'image' => ['src' => 'a.jpg', 'alt' => 'Coat'],
                'link' => ['label' => 'Shop now', 'url' => '/coats'], 'note' => 'internal'],
            'h2' => ['uid' => 'h2', 'component' => 'heading', 'text' => '  '],
        ]];
    }
}
