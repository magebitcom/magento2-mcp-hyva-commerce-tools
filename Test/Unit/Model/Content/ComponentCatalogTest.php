<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Component\Collector;
use Hyva\CmsLiveviewEditor\Model\Component\CollectorFactory;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentCatalog;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\FieldValueFormat;
use Magebit\McpHyvaCommerceTools\Model\Content\MenuOptionScope;
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
            ['type', 'value', 'label', 'open_in_new_tab', 'prefix', 'suffix', 'placeholder', 'aria_label'],
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
        $collector = $this->createMock(Collector::class);
        $collector->method('execute')->willThrowException(new \RuntimeException('Duplicate field names'));
        $factory = $this->createMock(CollectorFactory::class);
        $factory->method('create')->willReturn($collector);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('Duplicate field names'),
            $this->callback(static fn (array $context): bool => ($context['component'] ?? null) === 'picker')
        );
        $provider = $this->provider($cached);
        $catalog = new ComponentCatalog(
            $provider,
            new ComponentTree($provider),
            new FieldValueFormat(),
            $factory,
            $logger,
            $this->menuScope()
        );

        $options = $this->field($catalog->getSchema('picker'), 'choice')['options'];

        $this->assertSame([['value' => 'a', 'label' => 'A']], $options);
    }

    public function testComponentsWithoutOptionFieldsAreNotCollectedAgain(): void
    {
        $cached = ['card' => [
            'content' => ['title' => ['type' => 'text'], 'note' => ['type' => 'text', 'options' => 'unresolved']],
            'design' => ['align' => ['type' => 'text-align']],
        ]];
        $factory = $this->createMock(CollectorFactory::class);
        $factory->expects($this->never())->method('create');
        $provider = $this->provider($cached);
        $catalog = new ComponentCatalog(
            $provider,
            new ComponentTree($provider),
            new FieldValueFormat(),
            $factory,
            $this->createMock(LoggerInterface::class),
            $this->menuScope()
        );

        $fields = $catalog->getSchema('card')['fields'];
        $this->assertIsArray($fields);
        $this->assertCount(3, $fields);
    }

    public function testEachSchemaCallUsesAFreshCollector(): void
    {
        $cached = ['picker' => ['content' => ['choice' => ['type' => 'select', 'options' => []]]]];
        $collector = $this->createMock(Collector::class);
        $collector->method('execute')->willReturn($cached);
        $factory = $this->createMock(CollectorFactory::class);
        $factory->expects($this->exactly(2))->method('create')->willReturn($collector);
        $provider = $this->provider($cached);
        $catalog = new ComponentCatalog(
            $provider,
            new ComponentTree($provider),
            new FieldValueFormat(),
            $factory,
            $this->createMock(LoggerInterface::class),
            $this->menuScope()
        );

        $catalog->getSchema('picker');
        $catalog->getSchema('picker');
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
        $factory = $this->createMock(CollectorFactory::class);
        $factory->expects($this->never())->method('create');
        $provider = $this->provider($cached);
        $catalog = new ComponentCatalog(
            $provider,
            new ComponentTree($provider),
            new FieldValueFormat(),
            $factory,
            $this->createMock(LoggerInterface::class),
            $this->menuScope()
        );

        $this->assertCount(1, $catalog->getSummaries());
    }

    public function testMenuOptionsKeepOnlyMenusOfReadableStoreViews(): void
    {
        $components = $this->menuWidget();
        $menus = [
            ['id' => 1, 'identifier' => 'main', 'store_ids' => [1]],
            ['id' => 2, 'identifier' => 'footer', 'store_ids' => [2]],
            ['id' => 3, 'identifier' => 'shared', 'store_ids' => [1]],
            ['id' => 4, 'identifier' => 'shared', 'store_ids' => [2]],
        ];
        $scope = $this->menuScope(true, true, $menus, [2]);

        $field = $this->field(
            $this->catalog($this->provider($components), $components, $scope)->getSchema('hyva_menu_widget'),
            'menu_identifier'
        );

        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $this->assertSame(['', 'footer', 'shared'], array_column($options, 'value'));
        $this->assertSame(
            ['Please select a menu', 'Footer (ID: 2)', 'Shared (ID: 4)'],
            array_column($options, 'label'),
            'The store view 1 menu sharing an identifier with a readable menu stays hidden.'
        );
        $this->assertSame(2, $field['options_hidden_by_permissions'] ?? null);
    }

    public function testMenuOptionsEmptyForACallerWhoMayNotViewMenus(): void
    {
        $components = $this->menuWidget();
        $scope = $this->menuScope(false, false, [['id' => 1, 'identifier' => 'main', 'store_ids' => [0]]], [0]);

        $field = $this->field(
            $this->catalog($this->provider($components), $components, $scope)->getSchema('hyva_menu_widget'),
            'menu_identifier'
        );

        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $this->assertSame([''], array_column($options, 'value'));
        $this->assertSame(4, $field['options_hidden_by_permissions'] ?? null);
    }

    public function testMenuOptionsUnchangedForAnUnrestrictedMenuViewer(): void
    {
        $components = $this->menuWidget();
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->expects($this->never())->method('getList');
        $scopeFilter = $this->createMock(ContentScopeFilter::class);
        $scopeFilter->method('isRestricted')->willReturn(false);
        $scopeFilter->method('viewableTypes')->willReturnArgument(0);

        $field = $this->field(
            $this->catalog(
                $this->provider($components),
                $components,
                new MenuOptionScope($scopeFilter, $gateway)
            )->getSchema('hyva_menu_widget'),
            'menu_identifier'
        );

        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $this->assertCount(5, $options);
        $this->assertArrayNotHasKey('options_hidden_by_permissions', $field);
    }

    public function testOtherComponentsOptionsAreNotScoped(): void
    {
        $components = ['picker' => ['content' => ['menu_identifier' => [
            'type' => 'select',
            'options' => [['value' => 'main', 'label' => 'Main']],
        ]]]];
        $scope = $this->menuScope(true, false);

        $field = $this->field(
            $this->catalog($this->provider($components), $components, $scope)->getSchema('picker'),
            'menu_identifier'
        );

        $this->assertSame([['value' => 'main', 'label' => 'Main']], $field['options'] ?? null);
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
    private function catalog(
        ComponentProvider $provider,
        array $liveComponents,
        ?MenuOptionScope $menuScope = null
    ): ComponentCatalog {
        $collector = $this->createMock(Collector::class);
        $collector->method('execute')->willReturn($liveComponents);
        $factory = $this->createMock(CollectorFactory::class);
        $factory->method('create')->willReturn($collector);
        return new ComponentCatalog(
            $provider,
            new ComponentTree($provider),
            new FieldValueFormat(),
            $factory,
            $this->createMock(LoggerInterface::class),
            $menuScope ?? $this->menuScope()
        );
    }

    /**
     * @param bool $restricted
     * @param bool $menusViewable
     * @param list<array<string, mixed>> $menus
     * @param list<int> $readableStores
     * @return MenuOptionScope
     */
    private function menuScope(
        bool $restricted = false,
        bool $menusViewable = true,
        array $menus = [],
        array $readableStores = []
    ): MenuOptionScope {
        $scopeFilter = $this->createMock(ContentScopeFilter::class);
        $scopeFilter->method('isRestricted')->willReturn($restricted);
        $scopeFilter->method('viewableTypes')->willReturnCallback(
            static fn (array $types): array => $menusViewable ? $types : []
        );
        $scopeFilter->method('allowsStores')->willReturnCallback(
            static fn (array $storeIds): bool => array_intersect($storeIds, $readableStores) !== []
        );
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturn($menus);
        return new MenuOptionScope($scopeFilter, $gateway);
    }

    /**
     * @return array<string, mixed>
     */
    private function menuWidget(): array
    {
        return ['hyva_menu_widget' => [
            'label' => 'CMS Menu',
            'content' => ['menu_identifier' => [
                'type' => 'searchable_select',
                'label' => 'Menu',
                'options' => [
                    ['value' => '', 'label' => 'Please select a menu'],
                    ['value' => 'main', 'label' => 'Main (ID: 1)'],
                    ['value' => 'footer', 'label' => 'Footer (ID: 2)'],
                    ['value' => 'shared', 'label' => 'Shared (ID: 3)'],
                    ['value' => 'shared', 'label' => 'Shared (ID: 4)'],
                ],
            ]],
        ]];
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
