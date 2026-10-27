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
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class DraftEditorTest extends TestCase
{
    /**
     * @var ComponentTree
     */
    private ComponentTree $tree;
    /**
     * @var DraftEditor
     */
    private DraftEditor $editor;

    protected function setUp(): void
    {
        $components = [
            'row' => ['template' => 'row.phtml', 'children' => true],
            'heading' => [
                'template' => 'heading.phtml',
                'content' => [
                    'tag' => ['type' => 'select', 'default_value' => 'h2'],
                    'text' => ['type' => 'text', 'default_value' => 'Add text'],
                ],
            ],
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $this->tree = new ComponentTree($provider);
        $this->editor = new DraftEditor($this->tree);
    }

    public function testOperationsApplyInOrder(): void
    {
        $document = $this->tree->newDocument('cms_page', 1);
        $result = $this->editor->apply($document, [
            ['op' => 'add', 'component' => 'row', 'uid' => 'r1'],
            ['op' => 'add', 'component' => 'heading', 'parent_uid' => 'r1', 'fields' => ['text' => 'Old CTA']],
            ['op' => 'replace_text', 'find' => 'Old', 'replace' => 'New'],
        ]);

        $uid = $result['applied'][1]['uid'] ?? null;
        $this->assertIsString($uid);
        $heading = $this->tree->findNode($result['document'], $uid);
        $this->assertSame('New CTA', $heading['text'] ?? null);
        $this->assertSame('h2', $heading['tag'] ?? null, 'Defaults are filled in.');
        $this->assertSame(1, $result['applied'][2]['replacements']);
    }

    public function testBuildNodeAcceptsRawNodeFields(): void
    {
        $node = $this->editor->buildNode(['component' => 'heading', 'text' => 'Raw', 'children' => []]);
        $this->assertSame('Raw', $node['text']);
        $this->assertSame('heading.phtml', $node['componentPath']);
    }

    public function testFailedOperationReportsIndex(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Operation #1 (remove) failed');
        $this->editor->apply($this->tree->newDocument('cms_page', 1), [
            ['op' => 'add', 'component' => 'row'],
            ['op' => 'remove', 'uid' => 'missing'],
        ]);
    }

    public function testUnknownOperation(): void
    {
        $this->expectException(LocalizedException::class);
        $this->editor->apply($this->tree->newDocument('cms_page', 1), [['op' => 'explode']]);
    }

    public function testRemovingAndReplacingANodeWithChildrenReportsTheDroppedSubtree(): void
    {
        $document = $this->editor->apply($this->tree->newDocument('cms_page', 1), [
            ['op' => 'add', 'component' => 'row', 'uid' => 'r1'],
            ['op' => 'add', 'component' => 'heading', 'parent_uid' => 'r1', 'uid' => 'h1'],
            ['op' => 'add', 'component' => 'row', 'uid' => 'r2'],
            ['op' => 'add', 'component' => 'heading', 'parent_uid' => 'r2', 'uid' => 'h2'],
            ['op' => 'add', 'component' => 'row', 'uid' => 'r3'],
        ])['document'];

        $result = $this->editor->apply($document, [
            ['op' => 'remove', 'uid' => 'r1'],
            ['op' => 'replace_node', 'uid' => 'r2', 'node' => ['component' => 'row', 'uid' => 'r2b']],
            ['op' => 'remove', 'uid' => 'r3'],
        ]);

        $this->assertSame(['r1', 'r2'], array_column($result['dropped'], 'uid'));
        $children = $result['dropped'][0]['children'] ?? null;
        $this->assertIsArray($children);
        $this->assertSame('h1', is_array($children[0] ?? null) ? $children[0]['uid'] ?? null : null);
    }

    public function testMovingChildrenOutBeforeRemovingTheParentDropsNothing(): void
    {
        $document = $this->editor->apply($this->tree->newDocument('cms_page', 1), [
            ['op' => 'add', 'component' => 'row', 'uid' => 'r1'],
            ['op' => 'add', 'component' => 'heading', 'parent_uid' => 'r1', 'uid' => 'h1'],
        ])['document'];

        $result = $this->editor->apply($document, [
            ['op' => 'move', 'uid' => 'h1'],
            ['op' => 'remove', 'uid' => 'r1'],
        ]);

        $this->assertSame([], $result['dropped']);
    }
}
