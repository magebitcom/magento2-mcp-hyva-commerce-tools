<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * T-74: usage rows carry menu_identifier only for a node that names a menu. PR #2 review: an in-use scan reads menus
 * the caller may not view.
 */
class ContentScannerTest extends TestCase
{
    public function testOnlyAMenuWidgetRowCarriesTheMenuIdentifier(): void
    {
        $nodes = [
            ['uid' => 'a1', 'component' => 'cms_block', 'block_id' => '5'],
            ['uid' => 'b2', 'component' => 'hyva_menu_widget', 'menu_identifier' => 'main'],
        ];
        $index = $this->createMock(ContentIndex::class);
        $index->method('getRows')->willReturn([
            ['id' => 9, 'identifier' => 'home', 'title' => 'Home', 'draft_content' => '{"x":1}'],
        ]);
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('parse')->willReturn(['root' => []]);
        $tree->method('walk')->willReturnCallback(static function (array $document, callable $visitor) use ($nodes) {
            foreach ($nodes as $node) {
                $visitor($node);
            }
        });
        $scanner = new ContentScanner(
            $index,
            $tree,
            $this->createMock(ResourceConnection::class),
            $this->createMock(FeatureCheckerInterface::class)
        );

        $matches = $scanner->findNodes(static fn (array $node): bool => true, ['cms_page']);

        $this->assertCount(2, $matches);
        $this->assertSame(
            ['entity_type' => 'cms_page', 'id' => 9, 'identifier' => 'home', 'title' => 'Home', 'edition' => 'draft',
                'uid' => 'a1', 'component' => 'cms_block'],
            $matches[0]
        );
        $this->assertArrayNotHasKey('menu_identifier', $matches[0]);
        $this->assertSame('main', $matches[1]['menu_identifier'] ?? null);
    }

    public function testMenusTheCallerMayNotViewAreReadOnlyForAnInUseScan(): void
    {
        $scanner = $this->menuScanner(['available' => false, 'installed' => true, 'enabled' => true]);

        $this->assertSame([], $scanner->getMenuRows());
        $this->assertSame([5], array_column($scanner->getMenuRows(true), 'id'));
    }

    public function testADisabledMenuBuilderIsNotReadEvenForAnInUseScan(): void
    {
        $scanner = $this->menuScanner(['available' => false, 'installed' => true, 'enabled' => false]);

        $this->assertSame([], $scanner->getMenuRows(true));
    }

    /**
     * Scanner over one stored menu (#5) and a Menu Builder in the given state.
     *
     * @param array{available: bool, installed: bool, enabled: bool} $state
     * @return ContentScanner
     */
    private function menuScanner(array $state): ContentScanner
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($state['available']);
        $features->method('getStatus')->willReturn($state + ['feature' => 'menu_builder', 'label' => 'Menu Builder',
            'version' => null, 'package' => '', 'acl_resource' => 'Hyva_MenuBuilder::menu_view',
            'acl_allowed' => $state['available'], 'modules' => []]);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([['id' => 5, 'identifier' => 'main', 'title' => 'Main']]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ContentScanner(
            $this->createMock(ContentIndex::class),
            $this->createMock(ComponentTree::class),
            $resource,
            $features
        );
    }
}
