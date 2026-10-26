<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Merchandising;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Product as CategoryProductIndexer;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Product order of a category, as the Hyvä Category Merchandiser edits it.
 *
 * The merchandiser adds no save code: it posts the category's products with positions to the core category save
 * (catalog_category_product.position, global). This class does the same, but only reorders: membership never
 * changes here.
 *
 * @phpstan-type PositionRow array{position: int, product_id: int, sku: string, name: string, enabled: bool,
 *     visible: bool}
 */
class CategoryPositions
{
    /**
     * Modules that take over storefront category sorting, so stored positions may not apply there.
     */
    private const SORT_OWNERS = ['Smile_ElasticsuiteCatalog' => 'ElasticSuite (Smile) sorts category pages itself '
        . 'and ignores these positions; set its own product positions in the category admin instead.'];

    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryResource $categoryResource
     * @param ProductCollectionFactory $productCollectionFactory
     * @param IndexerRegistry $indexerRegistry
     * @param ModuleManager $moduleManager
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryResource $categoryResource,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ModuleManager $moduleManager
    ) {
    }

    /**
     * Category summary and its first products in stored order (position, then product id)
     *
     * @param int $categoryId
     * @param int $limit Products to return (only these are loaded)
     * @return array
     * @throws LocalizedException
     * @phpstan-return array{category: array{id: int, name: string, path: string, product_count: int},
     *     products: list<PositionRow>, notes: list<string>}
     */
    public function get(int $categoryId, int $limit = PHP_INT_MAX): array
    {
        $category = $this->category($categoryId);
        $positions = $this->positions($category);
        $first = $positions;
        uksort($first, static fn (int $a, int $b): int => [$first[$a], $a] <=> [$first[$b], $b]);
        $first = array_slice($first, 0, $limit, true);
        return [
            'category' => [
                'id' => $categoryId,
                'name' => Cast::string($category->getName()),
                'path' => Cast::string($category->getPath()),
                'product_count' => count($positions),
            ],
            'products' => $this->rows($first),
            'notes' => $this->notes(),
        ];
    }

    /**
     * New order: the given SKUs first (in that order), the other products after them in their current order
     *
     * @param int $categoryId
     * @param list<string> $topSkus
     * @return array
     * @throws LocalizedException When a SKU is not a product of the category.
     * @phpstan-return array{before: list<PositionRow>, after: list<PositionRow>, moved: int,
     *     positions: array<int, int>}
     */
    public function plan(int $categoryId, array $topSkus): array
    {
        $category = $this->category($categoryId);
        $before = $this->rows($this->positions($category));
        $bySku = [];
        foreach ($before as $row) {
            $bySku[mb_strtolower($row['sku'])] = $row;
        }
        $top = [];
        $missing = [];
        foreach ($topSkus as $sku) {
            $row = $bySku[mb_strtolower($sku)] ?? null;
            if ($row === null) {
                $missing[] = $sku;
            } elseif (!isset($top[$row['product_id']])) {
                $top[$row['product_id']] = $row;
            }
        }
        if ($missing !== []) {
            throw new LocalizedException(__(
                'Not products of this category: %1. This tool only reorders; assign products to the category first.',
                implode(', ', $missing)
            ));
        }
        $ordered = array_merge(
            array_values($top),
            array_values(array_filter($before, static fn (array $row): bool => !isset($top[$row['product_id']])))
        );
        $positions = [];
        $after = [];
        $moved = 0;
        foreach ($ordered as $index => $row) {
            $positions[$row['product_id']] = $index;
            $moved += $row['position'] !== $index ? 1 : 0;
            $after[] = ['position' => $index] + $row;
        }
        return ['before' => $before, 'after' => $after, 'moved' => $moved, 'positions' => $positions];
    }

    /**
     * Store new positions through the core category save (same products, new positions)
     *
     * @param int $categoryId
     * @param array $positions product id => position, for every product of the category
     * @return void
     * @throws LocalizedException
     * @phpstan-param array<int, int> $positions
     */
    public function save(int $categoryId, array $positions): void
    {
        $category = $this->category($categoryId);
        if (array_diff_key($this->positions($category), $positions) !== []
            || array_diff_key($positions, $this->positions($category)) !== []) {
            throw new LocalizedException(__('The category products changed since the preview; preview again.'));
        }
        $category->setData('posted_products', $positions);
        $this->categoryResource->save($category);
    }

    /**
     * Storefront caveats: a module that owns sorting, or a scheduled index.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        $notes = [];
        foreach (self::SORT_OWNERS as $module => $note) {
            if ($this->moduleManager->isEnabled($module)) {
                $notes[] = $note;
            }
        }
        if ($this->indexerRegistry->get(CategoryProductIndexer::INDEXER_ID)->isScheduled()) {
            $notes[] = 'The category products index updates by schedule: a change shows on the storefront after '
                . 'the next indexer cron run.';
        }
        return $notes;
    }

    /**
     * Category at the default scope (positions are global).
     *
     * @param int $categoryId
     * @return Category
     * @throws LocalizedException
     */
    private function category(int $categoryId): Category
    {
        try {
            $category = $this->categoryRepository->get($categoryId, 0);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('There is no category with id %1.', $categoryId));
        }
        if (!$category instanceof Category) {
            throw new LocalizedException(__('Category %1 cannot be merchandised.', $categoryId));
        }
        return $category;
    }

    /**
     * Product id => position, read from the database (not the category's cached copy).
     *
     * @param Category $category
     * @return array<int, int>
     */
    private function positions(Category $category): array
    {
        $positions = [];
        foreach ($this->categoryResource->getProductsPosition($category) as $productId => $position) {
            $positions[(int) $productId] = (int) $position;
        }
        return $positions;
    }

    /**
     * Rows in stored order with product details (one collection load).
     *
     * @param array $positions
     * @return array
     * @phpstan-param array<int, int> $positions
     * @phpstan-return list<PositionRow>
     */
    private function rows(array $positions): array
    {
        if ($positions === []) {
            return [];
        }
        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'status', 'visibility'])
            ->addIdFilter(array_keys($positions));
        $details = [];
        foreach ($collection->getItems() as $product) {
            $details[Cast::int($product->getData('entity_id'))] = $product;
        }
        $rows = [];
        foreach ($positions as $productId => $position) {
            $product = $details[$productId] ?? null;
            $rows[] = [
                'position' => $position,
                'product_id' => $productId,
                'sku' => $product !== null ? Cast::string($product->getData('sku')) : '',
                'name' => $product !== null ? Cast::string($product->getData('name')) : '',
                'enabled' => $product !== null && Cast::int($product->getData('status')) === 1,
                'visible' => $product !== null && Cast::int($product->getData('visibility')) > 1,
            ];
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => [
                $a['position'],
                $a['product_id'],
            ] <=> [$b['position'], $b['product_id']]
        );
        return $rows;
    }
}
