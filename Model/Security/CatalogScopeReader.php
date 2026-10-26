<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Security;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;

/**
 * Reads what the scope checks of catalog entities need, for many ids in one query: the websites of products
 * (catalog_product_website) and the tree paths of categories.
 */
class CatalogScopeReader
{
    private const IDS_PER_QUERY = 5000;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Website ids per product id (an empty list for a product in no website); missing products are left out.
     *
     * @param array $productIds
     * @return array
     * @phpstan-param list<int> $productIds
     * @phpstan-return array<int, list<int>>
     */
    public function productWebsiteIds(array $productIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $websiteIds = [];
        foreach (array_chunk(array_values(array_unique($productIds)), self::IDS_PER_QUERY) as $chunk) {
            $select = $connection->select()
                ->from(['e' => $this->resourceConnection->getTableName('catalog_product_entity')], ['entity_id'])
                ->joinLeft(
                    ['w' => $this->resourceConnection->getTableName('catalog_product_website')],
                    'w.product_id = e.entity_id',
                    ['website_id']
                )
                ->where('e.entity_id IN (?)', $chunk);
            foreach ($connection->fetchAll($select) as $row) {
                $id = Cast::int($row['entity_id']);
                $websiteIds[$id] ??= [];
                if ($row['website_id'] !== null) {
                    $websiteIds[$id][] = Cast::int($row['website_id']);
                }
            }
        }
        return $websiteIds;
    }

    /**
     * Tree path (e.g. "1/2/15") per category id; missing categories are left out.
     *
     * @param array $categoryIds
     * @return array
     * @phpstan-param list<int> $categoryIds
     * @phpstan-return array<int, string>
     */
    public function categoryPaths(array $categoryIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $paths = [];
        foreach (array_chunk(array_values(array_unique($categoryIds)), self::IDS_PER_QUERY) as $chunk) {
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(
                        $this->resourceConnection->getTableName('catalog_category_entity'),
                        ['entity_id', 'path']
                    )
                    ->where('entity_id IN (?)', $chunk)
            );
            foreach ($rows as $id => $path) {
                $paths[Cast::int($id)] = Cast::string($path);
            }
        }
        return $paths;
    }
}
