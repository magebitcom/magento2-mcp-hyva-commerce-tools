<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentCatalog;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\FieldValueFormat;
use PHPUnit\Framework\TestCase;

class ComponentCatalogTest extends TestCase
{
    public function testEveryFieldCarriesItsValueFormat(): void
    {
        $components = ['button' => [
            'label' => 'Button',
            'content' => [
                'title' => ['type' => 'text', 'label' => 'Title'],
                'link' => ['type' => 'link', 'label' => 'Link'],
                'image' => ['type' => 'image', 'label' => 'Icon'],
            ],
            'design' => ['align' => ['type' => 'text-align'], 'custom' => ['type' => 'my_custom_type']],
        ]];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $catalog = new ComponentCatalog($provider, new ComponentTree($provider), new FieldValueFormat());

        $fields = [];
        $schema = $catalog->getSchema('button');
        $this->assertIsArray($schema['fields']);
        foreach ($schema['fields'] as $field) {
            $this->assertIsArray($field);
            $fields[(string) $field['name']] = $field;
        }

        $link = $fields['link']['value_format'] ?? null;
        $this->assertIsArray($link);
        $this->assertIsArray($link['example']);
        $this->assertSame(
            ['type', 'value', 'label', 'open_in_new_tab', 'prefix', 'suffix', 'placeholder'],
            array_keys($link['example'])
        );
        $this->assertStringContainsString('SKU', (string) $link['notes']);
        $this->assertStringContainsString('STORE_URL', (string) $link['notes']);
        $image = $fields['image']['value_format'] ?? null;
        $this->assertIsArray($image);
        $this->assertSame('wysiwyg/banner.jpg', is_array($image['example']) ? $image['example']['src'] : null);
        $this->assertSame('string', $fields['title']['value_format']['format'] ?? null);
        $this->assertStringContainsString('text-center', (string) ($fields['align']['value_format']['format'] ?? ''));
        $this->assertArrayNotHasKey('value_format', $fields['custom'], 'Unknown custom types get no invented format.');
    }

    public function testEveryInstalledFieldTypeIsDocumented(): void
    {
        $formats = new FieldValueFormat();
        $installed = [
            'boolean', 'text', 'select', 'color', 'number', 'range', 'image', 'link', 'variant', 'category_importer',
            'richtext', 'searchable_select', 'category_selector', 'textarea', 'text-align', 'preset', 'url', 'html',
            'products', 'widget', 'multiselect', 'date', 'datetime', 'option_list',
        ];
        foreach ($installed as $type) {
            $this->assertNotNull($formats->get($type), $type . ' has no value format');
        }
    }
}
