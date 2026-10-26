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
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ComponentTreeTest extends TestCase
{
    /**
     * @var ComponentTree
     */
    private ComponentTree $tree;

    /**
     * @var ComponentProvider
     */
    private ComponentProvider $provider;

    protected function setUp(): void
    {
        $components = [
            'row' => ['name' => 'row', 'template' => 'Hyva_CmsBase::elements/row.phtml', 'children' => true,
                'content' => ['appearance' => ['type' => 'select', 'default_value' => 'default',
                    'options' => [
                        ['label' => 'Default', 'value' => 'default'],
                        ['label' => 'Full', 'value' => 'full'],
                    ]]]],
            'heading' => ['name' => 'heading', 'template' => 'Hyva_CmsBase::elements/heading.phtml',
                'content' => [
                    'tag' => ['type' => 'select', 'default_value' => 'h2',
                        'options' => [['label' => 'H1', 'value' => 'h1'], ['label' => 'H2', 'value' => 'h2']]],
                    'text' => ['type' => 'textarea', 'default_value' => 'Add your text here'],
                ]],
            'accordion_list' => ['name' => 'accordion_list', 'template' => 'x.phtml',
                'children' => ['config' => ['accepts' => ['accordion_item'], 'max_children' => 2]]],
            'accordion_item' => ['name' => 'accordion_item', 'template' => 'y.phtml', 'require_parent' => true,
                'children' => true,
                'content' => ['title' => ['type' => 'text', 'attributes' => ['required' => true]]]],
            'hyva_form' => ['name' => 'hyva_form', 'template' => 'f.phtml', 'protected_root' => true,
                'root_only' => true, 'children' => true],
            'form_field_select' => ['name' => 'form_field_select', 'template' => 's.phtml', 'require_parent' => true,
                'content' => ['options' => ['type' => 'option_list',
                    'attributes' => ['default_selection' => 'single']]]],
            'hyva_form_widget' => ['name' => 'hyva_form_widget', 'template' => 'w.phtml'],
            'image' => ['name' => 'image', 'template' => 'i.phtml',
                'content' => ['image' => ['type' => 'image'], 'caption' => ['type' => 'text']]],
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $name) => $components[$name] ?? null);
        $provider->method('isComponentDisabled')->willReturnCallback(static fn (string $name) => $name === 'legacy');
        $this->provider = $provider;
        $this->tree = new ComponentTree($provider);
    }

    public function testParseEmptyReturnsSkeleton(): void
    {
        $document = $this->tree->parse('', 'cms_page', 7);
        $this->assertSame('elements/ROOT.phtml', $document['componentPath']);
        $this->assertSame('cms_page', $document['contentType']);
        $this->assertSame('7', $document['contentId']);
        $this->assertSame([], $document['content']);
        $this->assertNotEmpty($document['uid']);
    }

    public function testParseRejectsInvalidJson(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tree->parse('{not json', 'cms_page', 1);
    }

    public function testCreateNodeAppliesDefaultsAndFields(): void
    {
        $node = $this->tree->createNode('heading', ['text' => 'Black Friday', 'uid' => 'ignored']);
        $this->assertSame('heading', $node['component']);
        $this->assertSame('Hyva_CmsBase::elements/heading.phtml', $node['componentPath']);
        $this->assertSame('h2', $node['tag']);
        $this->assertSame('Black Friday', $node['text']);
        $this->assertNotSame('ignored', $node['uid']);
        $this->assertSame([], $node['children']);
    }

    public function testCreateNodeRejectsUnknownComponent(): void
    {
        $this->expectException(LocalizedException::class);
        $this->tree->createNode('does_not_exist');
    }

    public function testInsertFindMoveRemoveKeepsUidKeyedRoot(): void
    {
        $document = $this->tree->newDocument('cms_page', 1);
        $row = $this->node('row', 'row1');
        $heading = $this->node('heading', 'h1', ['text' => 'Hello']);
        $document = $this->tree->insertNode($document, $row);
        $document = $this->tree->insertNode($document, $heading, 'row1');

        $this->assertSame(['row1'], array_keys($this->arr($document['content'])));
        $this->assertSame('row1', $this->tree->findParentUid($document, 'h1'));
        $this->assertSame('Hello', $this->tree->findNode($document, 'h1')['text'] ?? null);

        $document = $this->tree->moveNode($document, 'h1', null, 0);
        $this->assertSame(['h1', 'row1'], array_keys($this->arr($document['content'])));
        $this->assertNull($this->tree->findParentUid($document, 'h1'));

        $result = $this->tree->removeNode($document, 'row1');
        $this->assertSame('row1', $result['removed']['uid']);
        $this->assertSame(['h1'], array_keys($this->arr($result['document']['content'])));
    }

    public function testListRootIsRewrittenAsUidMap(): void
    {
        $document = $this->tree->normalizeDocument(['content' => [$this->node('row', 'a')]], 'menu', 3);
        $document = $this->tree->insertNode($document, $this->node('row', 'b'), null, 0);
        $this->assertSame(['b', 'a'], array_keys($this->arr($document['content'])));
    }

    public function testMoveIntoOwnSubtreeIsRejected(): void
    {
        $document = $this->tree->newDocument('cms_page', 1);
        $document = $this->tree->insertNode($document, $this->node('row', 'outer'));
        $document = $this->tree->insertNode($document, $this->node('row', 'inner'), 'outer');
        $this->expectException(LocalizedException::class);
        $this->tree->moveNode($document, 'outer', 'inner');
    }

    public function testSetFieldAndReplaceNode(): void
    {
        $document = $this->tree->newDocument('cms_page', 1);
        $document = $this->tree->insertNode($document, $this->node('row', 'r'));
        $document = $this->tree->insertNode($document, $this->node('heading', 'h', ['text' => 'Old']), 'r');
        $document = $this->tree->insertNode($document, $this->node('heading', 'h2', ['text' => 'Second']), 'r');

        $document = $this->tree->setField($document, 'h', 'text', 'New');
        $this->assertSame('New', $this->tree->findNode($document, 'h')['text'] ?? null);

        $document = $this->tree->replaceNode($document, 'h', $this->node('heading', 'hx', ['text' => 'Swapped']));
        $children = $this->tree->findNode($document, 'r')['children'] ?? [];
        $this->assertSame(['hx', 'h2'], array_column($this->arr($children), 'uid'));
    }

    public function testSetFieldRejectsReservedKey(): void
    {
        $document = $this->tree->insertNode($this->tree->newDocument('cms_page', 1), $this->node('row', 'r'));
        $this->expectException(LocalizedException::class);
        $this->tree->setField($document, 'r', 'children', []);
    }

    public function testValidateReportsStructuralProblems(): void
    {
        $document = $this->tree->newDocument('cms_page', 1);
        $document = $this->tree->insertNode(
            $document,
            $this->node('heading', 'h', ['tag' => 'h9'], [$this->node('row', 'child')])
        );
        $document = $this->tree->insertNode($document, $this->node('accordion_item', 'orphan'));
        $document = $this->tree->insertNode($document, $this->node('unknown_thing', 'u'));
        $document = $this->tree->insertNode($document, $this->node('legacy', 'l'));
        $document = $this->tree->insertNode($document, $this->node('accordion_list', 'al', [], [
            $this->node('accordion_item', 'i1', ['title' => 'One']),
            $this->node('heading', 'bad'),
            $this->node('accordion_item', 'i2', ['title' => 'Two']),
        ]));

        $codes = array_column($this->tree->validate($document), 'code');
        $this->assertContains('invalid_option', $codes);
        $this->assertContains('children_not_allowed', $codes);
        $this->assertContains('requires_parent', $codes);
        $this->assertContains('missing_required_field', $codes);
        $this->assertContains('unknown_component', $codes);
        $this->assertContains('disabled_component', $codes);
        $this->assertContains('invalid_child', $codes);
        $this->assertContains('too_many_children', $codes);
    }

    public function testValidDocumentHasNoIssues(): void
    {
        $document = $this->tree->newDocument('cms_page', 1);
        $document = $this->tree->insertNode(
            $document,
            $this->node('row', 'r', [], [$this->node('heading', 'h', ['tag' => 'h1'])])
        );
        $this->assertSame([], $this->tree->validate($document));
        $this->assertSame(['heading' => 1, 'row' => 1], $this->tree->countComponents($document));
    }

    public function testPrepareNodeAssignsUidsAndTemplates(): void
    {
        $node = $this->tree->prepareNode(
            ['component' => 'row', 'children' => [['component' => 'heading', 'text' => 'x']]]
        );
        $this->assertNotEmpty($node['uid']);
        $this->assertSame('Hyva_CmsBase::elements/row.phtml', $node['componentPath']);
        $child = $this->arr($this->arr($node['children'])[0] ?? null);
        $this->assertNotEmpty($child['uid']);
        $this->assertSame('Hyva_CmsBase::elements/heading.phtml', $child['componentPath']);
    }

    /**
     * Arr.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return array<mixed>
     */
    private function arr(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    /**
     * Node.
     *
     * @param string $component
     * @param string $uid
     * @param array $fields
     * @param array $children
     * @return array
     * @phpstan-param array<string, mixed> $fields
     * @phpstan-param list<array<string, mixed>> $children
     * @phpstan-return array<string, mixed>
     */
    private function node(string $component, string $uid, array $fields = [], array $children = []): array
    {
        return ['uid' => $uid, 'component' => $component, 'componentPath' => '', 'children' => $children] + $fields;
    }

    public function testImageFieldsGetAPathForAssetIdBackfill(): void
    {
        $document = $this->tree->insertNode($this->tree->newDocument('cms_page', 1), $this->tree->createNode('image', [
            'image' => ['src' => 'wysiwyg/a.jpg', 'alt' => 'A'],
            'caption' => 'not an image',
        ]));
        $document = $this->tree->insertNode($document, $this->tree->createNode('image', [
            'image' => ['src' => 'wysiwyg/b.jpg', 'imageOptions' => ['id' => 7, 'path' => 'wysiwyg/b.jpg']],
        ]));

        $nodes = array_column($this->tree->flatten($this->tree->markImageFields($document)), 'node');

        $first = $nodes[0]['image'] ?? null;
        $second = $nodes[1]['image'] ?? null;
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame(['path' => 'wysiwyg/a.jpg'], $first['imageOptions'] ?? null);
        $this->assertSame('not an image', $nodes[0]['caption'] ?? null);
        $this->assertSame(['id' => 7, 'path' => 'wysiwyg/b.jpg'], $second['imageOptions'] ?? null);
    }

    public function testFormRootConstraintsAreEnforced(): void
    {
        $constraints = ['form' => [
            'root_component' => 'hyva_form',
            'max_root_components' => 1,
            'disallowed_components' => ['hyva_form_widget'],
        ]];
        $tree = new ComponentTree($this->provider, $constraints);
        $form = $tree->createNode('hyva_form', [], [$tree->createNode('form_field_select', ['options' => [
            ['label' => 'A', 'value' => 'a', 'default' => true],
            ['label' => 'B', 'value' => 'b', 'default' => true],
        ]])]);
        $document = $tree->insertNode($tree->newDocument('form', 1), $form);
        $codes = array_column($tree->validate($document), 'code');
        $this->assertSame(['too_many_defaults'], $codes, 'single default_selection allows one default row');

        $document = $tree->insertNode($document, $tree->createNode('hyva_form_widget'));
        $codes = array_column($tree->validate($document), 'code');
        $expected = ['root_component_required', 'too_many_root_components', 'disallowed_component', 'protected_root'];
        foreach ($expected as $code) {
            $this->assertContains($code, $codes);
        }

        $nested = $tree->insertNode($tree->newDocument('cms_page', 1), $tree->createNode('row', [], [
            $tree->createNode('hyva_form'),
        ]));
        $this->assertContains('root_only', array_column($tree->validate($nested), 'code'));

        $page = $tree->insertNode($tree->newDocument('cms_page', 1), $tree->createNode('hyva_form_widget'));
        $this->assertNotContains('disallowed_component', array_column($tree->validate($page), 'code'));
    }

    public function testListShapedPresetContentBecomesAUidMap(): void
    {
        $document = $this->tree->parse(
            (string) json_encode(
                ['uid' => 'doc', 'content' => [['uid' => 'r1', 'component' => 'row', 'children' => []]]]
            ),
            'email_template',
            3
        );
        $this->assertSame(['r1'], array_keys(is_array($document['content']) ? $document['content'] : []));
    }
}
