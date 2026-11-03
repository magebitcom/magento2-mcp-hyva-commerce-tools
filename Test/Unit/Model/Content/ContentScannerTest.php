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
use PHPUnit\Framework\TestCase;

/**
 * T-74: usage rows carry menu_identifier only for a node that names a menu.
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
}
