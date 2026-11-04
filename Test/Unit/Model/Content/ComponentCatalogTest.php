<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Component\Collector;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
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
        $catalog = $this->catalog($provider, $components);

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

    public function testOptionsSourceResolvedLive(): void
    {
        $placeholder = ['value' => '', 'label' => 'Please select a menu'];
        $cached = ['hyva_menu_widget' => [
            'label' => 'CMS Menu',
            'content' => ['menu_identifier' => [
                'type' => 'searchable_select',
                'label' => 'Menu',
                'options' => [$placeholder],
            ]],
        ]];
        $live = $cached;
        $live['hyva_menu_widget']['content']['menu_identifier']['options'] = [
            $placeholder,
            ['value' => 'main', 'label' => 'Main (ID: 1)'],
            ['value' => 'footer', 'label' => 'Footer (ID: 2)'],
        ];
        $provider = $this->provider($cached);

        $schema = $this->catalog($provider, $live)->getSchema('hyva_menu_widget');
        $options = $this->field($schema, 'menu_identifier')['options'];

        $this->assertSame(
            ['', 'main', 'footer'],
            array_column(is_array($options) ? $options : [], 'value'),
            'Menus created after the hyva_cms component cache was built must be listed.'
        );
    }

    public function testCachedOptionsKeptWhenLiveCollectionFails(): void
    {
        $cached = ['picker' => ['content' => ['choice' => [
            'type' => 'select',
            'options' => [['value' => 'a', 'label' => 'A']],
        ]]]];
        $factory = $this->createMock(OptionalServiceFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('Duplicate field names'));
        $provider = $this->provider($cached);
        $catalog = new ComponentCatalog($provider, new ComponentTree($provider), new FieldValueFormat(), $factory);

        $options = $this->field($catalog->getSchema('picker'), 'choice')['options'];

        $this->assertSame([['value' => 'a', 'label' => 'A']], $options);
    }

    public function testLiveOptionsOnlyReplaceFieldsThatHaveOptions(): void
    {
        $cached = ['card' => ['content' => [
            'title' => ['type' => 'text', 'label' => 'Title'],
            'style' => ['type' => 'select', 'options' => [['value' => 'old', 'label' => 'Old']]],
        ]]];
        $live = ['card' => ['content' => [
            'title' => ['type' => 'text', 'label' => 'Title', 'options' => 'not-an-array'],
            'style' => ['type' => 'select', 'options' => [['value' => 'new', 'label' => 'New']]],
        ]]];

        $schema = $this->catalog($this->provider($cached), $live)->getSchema('card');

        $this->assertArrayNotHasKey('options', $this->field($schema, 'title'));
        $this->assertSame([['value' => 'new', 'label' => 'New']], $this->field($schema, 'style')['options']);
    }

    public function testSummariesDoNotCollectComponentsAgain(): void
    {
        $cached = ['card' => ['content' => ['title' => ['type' => 'text']]]];
        $factory = $this->createMock(OptionalServiceFactory::class);
        $factory->expects($this->never())->method('create');
        $provider = $this->provider($cached);
        $catalog = new ComponentCatalog($provider, new ComponentTree($provider), new FieldValueFormat(), $factory);

        $this->assertCount(1, $catalog->getSummaries());
    }

    /**
     * @param array<string, mixed> $components
     * @return ComponentProvider&\PHPUnit\Framework\MockObject\MockObject
     */
    private function provider(array $components): ComponentProvider
    {
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(
            static fn (string $name): ?array => is_array($components[$name] ?? null) ? $components[$name] : null
        );
        return $provider;
    }

    /**
     * @param ComponentProvider $provider
     * @param array<string, mixed> $liveComponents what a fresh Hyvä collector run returns
     * @return ComponentCatalog
     */
    private function catalog(ComponentProvider $provider, array $liveComponents): ComponentCatalog
    {
        $collector = $this->createMock(Collector::class);
        $collector->method('execute')->willReturn($liveComponents);
        $factory = $this->createMock(OptionalServiceFactory::class);
        $factory->method('create')->with(Collector::class)->willReturn($collector);
        return new ComponentCatalog($provider, new ComponentTree($provider), new FieldValueFormat(), $factory);
    }

    /**
     * @param array<string, mixed> $schema
     * @param string $name
     * @return array<mixed>
     */
    private function field(array $schema, string $name): array
    {
        foreach (is_array($schema['fields']) ? $schema['fields'] : [] as $field) {
            if (is_array($field) && ($field['name'] ?? null) === $name) {
                return $field;
            }
        }
        $this->fail('No field ' . $name);
    }
}
