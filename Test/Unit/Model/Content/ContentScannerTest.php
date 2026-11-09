<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
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
 * the caller may not view. T-183: Form Builder form trees (draft and published) are scanned with their store views.
 */
class ContentScannerTest extends TestCase
{
    /**
     * Queries the fake form connection answered, in order.
     *
     * @var list<array{table: string, where: array<string, mixed>, limit: int|null}>
     */
    private array $formQueries = [];

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

    public function testFormNodesComeWithTheFormAndItsStoreViewsFromDraftAndPublished(): void
    {
        $root = static fn (string $uid, mixed $block): string => (string) json_encode([
            'uid' => 'tree', 'contentType' => 'form', 'contentId' => '3',
            'content' => [$uid => ['uid' => $uid, 'component' => 'hyva_form', 'children' => [],
                'success_content_block' => $block]],
        ]);
        $scanner = $this->formScanner(['available' => false, 'installed' => true, 'enabled' => true], [
            ['id' => 3, 'identifier' => 'contact', 'title' => 'Contact', 'draft_content' => $root('r1', 'promo'),
                'published_content' => $root('r1', null)],
            ['id' => 4, 'identifier' => 'quote', 'title' => 'Quote', 'draft_content' => '', 'published_content' => '[]'],
        ], [['form_id' => 3, 'store_id' => 1], ['form_id' => 3, 'store_id' => 2]]);

        $matches = $scanner->findFormNodes(
            static fn (array $node): bool => ($node['success_content_block'] ?? null) === 'promo'
        );

        // Read without the caller's form permission (in-use decision); only the draft names the block.
        $this->assertSame([[
            'entity_type' => 'form', 'id' => 3, 'identifier' => 'contact', 'title' => 'Contact', 'edition' => 'draft',
            'uid' => 'r1', 'component' => 'hyva_form', 'store_ids' => [1, 2],
        ]], $matches);
    }

    public function testFormsAreNotScannedWithoutAnEnabledFormBuilder(): void
    {
        $scanner = $this->formScanner(['available' => false, 'installed' => true, 'enabled' => false], [
            ['id' => 3, 'identifier' => 'contact', 'title' => 'Contact', 'draft_content' => '{"content":{}}'],
        ], []);

        $this->assertSame([], $scanner->findFormNodes(static fn (array $node): bool => true));
    }

    public function testFormsAreReadInKeysetBatchesWithEachBatchsOwnStoreViews(): void
    {
        $form = static fn (int $id, ?string $block): array => [
            'id' => $id, 'identifier' => 'form' . $id, 'title' => 'Form ' . $id,
            'draft_content' => (string) json_encode([
                'uid' => 'tree', 'contentType' => 'form', 'contentId' => (string) $id,
                'content' => ['r' . $id => ['uid' => 'r' . $id, 'component' => 'hyva_form', 'children' => [],
                    'success_content_block' => $block]],
            ]),
            'published_content' => '',
        ];
        // Five forms in batches of two: [2, 4], [6, 8], [9]. Matches in the first and the last batch.
        $scanner = $this->formScanner(
            ['available' => false, 'installed' => true, 'enabled' => true],
            [$form(9, 'promo'), $form(2, 'promo'), $form(4, null), $form(6, 'other'), $form(8, null)],
            [
                ['form_id' => 2, 'store_id' => 1],
                ['form_id' => 9, 'store_id' => 3],
                ['form_id' => 9, 'store_id' => 4],
            ],
            2
        );

        $matches = $scanner->findFormNodes(
            static fn (array $node): bool => ($node['success_content_block'] ?? null) === 'promo'
        );

        $this->assertSame([[2, [1]], [9, [3, 4]]], array_map(
            static fn (array $match): array => [$match['id'], $match['store_ids']],
            $matches
        ));
        $this->assertSame([
            ['hyva_commerce_cms_form', ['id > ?' => 0], 2],
            ['hyva_commerce_cms_form_store', ['form_id IN (?)' => [2, 4]], null],
            ['hyva_commerce_cms_form', ['id > ?' => 4], 2],
            ['hyva_commerce_cms_form_store', ['form_id IN (?)' => [6, 8]], null],
            ['hyva_commerce_cms_form', ['id > ?' => 8], 2],
            ['hyva_commerce_cms_form_store', ['form_id IN (?)' => [9]], null],
        ], $this->queryLog());
    }

    public function testAnEmptyBatchEndsTheScanWithoutAStoreQuery(): void
    {
        $form = static fn (int $id): array => [
            'id' => $id, 'identifier' => 'form' . $id, 'title' => 'Form ' . $id,
            'draft_content' => '', 'published_content' => '',
        ];
        $scanner = $this->formScanner(
            ['available' => false, 'installed' => true, 'enabled' => true],
            [$form(1), $form(2)],
            [],
            2
        );

        $this->assertSame([], $scanner->findFormNodes(static fn (array $node): bool => true));
        // A full batch needs one more read; it comes back empty and no store links are queried for it.
        $this->assertSame([
            ['hyva_commerce_cms_form', ['id > ?' => 0], 2],
            ['hyva_commerce_cms_form_store', ['form_id IN (?)' => [1, 2]], null],
            ['hyva_commerce_cms_form', ['id > ?' => 2], 2],
        ], $this->queryLog());
    }

    public function testNoFormsMeansNoStoreQuery(): void
    {
        $scanner = $this->formScanner(['available' => false, 'installed' => true, 'enabled' => true], [], []);

        $this->assertSame([], $scanner->findFormNodes(static fn (array $node): bool => true));
        $this->assertSame(
            [['hyva_commerce_cms_form', ['id > ?' => 0], ContentScanner::FORM_BATCH_SIZE]],
            $this->queryLog()
        );
    }

    /**
     * Recorded form queries as [table, where, limit].
     *
     * @return list<array{0: string, 1: array<string, mixed>, 2: int|null}>
     */
    private function queryLog(): array
    {
        return array_map(
            static fn (array $query): array => [$query['table'], $query['where'], $query['limit']],
            $this->formQueries
        );
    }

    /**
     * Scanner over stored forms and their store links, with a Form Builder in the given state.
     *
     * The fake connection answers the keyset batch query (id > ?, LIMIT) and the per-batch store link query
     * (form_id IN (?)), and records every query in $this->formQueries.
     *
     * @param array{available: bool, installed: bool, enabled: bool} $state
     * @param list<array<string, mixed>> $forms
     * @param list<array<string, int>> $storeLinks
     * @param int $batchSize
     * @return ContentScanner
     */
    private function formScanner(
        array $state,
        array $forms,
        array $storeLinks,
        int $batchSize = ContentScanner::FORM_BATCH_SIZE
    ): ContentScanner {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($state['available']);
        $features->method('getStatus')->with(FeatureCheckerInterface::FORM_BUILDER)->willReturn($state + [
            'feature' => 'form_builder', 'label' => 'Form Builder', 'unavailable_reason' => null, 'version' => null,
            'package' => '', 'acl_resource' => null, 'acl_allowed' => null, 'modules' => [],
        ]);
        $tables = [];
        $wheres = [];
        $limits = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturnCallback(function () use (&$tables, &$wheres, &$limits): Select {
            $select = $this->createMock(Select::class);
            $key = spl_object_id($select);
            $select->method('from')->willReturnCallback(
                static function (string $table) use ($select, $key, &$tables): Select {
                    $tables[$key] = $table;
                    return $select;
                }
            );
            $select->method('where')->willReturnCallback(
                static function (string $condition, mixed $value) use ($select, $key, &$wheres): Select {
                    $wheres[$key][$condition] = $value;
                    return $select;
                }
            );
            $select->method('order')->willReturnSelf();
            $select->method('limit')->willReturnCallback(
                static function (int $count) use ($select, $key, &$limits): Select {
                    $limits[$key] = $count;
                    return $select;
                }
            );
            return $select;
        });
        $connection->method('fetchAll')->willReturnCallback(
            function (Select $select) use (&$tables, &$wheres, &$limits, $forms, $storeLinks): array {
                $key = spl_object_id($select);
                $table = $tables[$key] ?? '';
                $where = $wheres[$key] ?? [];
                $limit = $limits[$key] ?? null;
                $this->formQueries[] = ['table' => $table, 'where' => $where, 'limit' => $limit];
                if ($table === 'hyva_commerce_cms_form') {
                    $after = $where['id > ?'] ?? null;
                    $rows = array_values(array_filter($forms, static fn (array $row): bool => $row['id'] > $after));
                    usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
                    return array_slice($rows, 0, $limit);
                }
                $ids = $where['form_id IN (?)'] ?? null;
                return array_values(array_filter(
                    $storeLinks,
                    static fn (array $link): bool => is_array($ids) && in_array($link['form_id'], $ids, true)
                ));
            }
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ContentScanner(
            $this->createMock(ContentIndex::class),
            new ComponentTree($this->createMock(ComponentProvider::class)),
            $resource,
            $features,
            $batchSize
        );
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
