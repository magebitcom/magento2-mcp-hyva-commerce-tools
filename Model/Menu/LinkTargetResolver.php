<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Menu;

use Magebit\McpHyvaCommerceTools\Api\LinkTargetResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;

class LinkTargetResolver implements LinkTargetResolverInterface
{
    /**
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param ProductCollectionFactory $productCollectionFactory
     * @param ContentIndex $contentIndex
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ContentIndex $contentIndex,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCategories(array $ids, int $storeId = 0): array
    {
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = [
                'exists' => false,
                'active' => false,
                'include_in_menu' => false,
                'name' => '',
                'has_url_rewrite' => false,
            ];
        }
        if ($ids === []) {
            return $result;
        }
        $rewrites = $this->categoriesWithRewrite($ids, $storeId);
        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'is_active', 'include_in_menu'])
            ->addFieldToFilter('entity_id', ['in' => $ids]);
        foreach ($collection->getItems() as $category) {
            if (!$category instanceof DataObject) {
                continue;
            }
            $id = Cast::int($category->getData('entity_id'));
            $result[$id] = [
                'exists' => true,
                'active' => Cast::bool($category->getData('is_active')),
                'include_in_menu' => Cast::bool($category->getData('include_in_menu')),
                'name' => Cast::string($category->getData('name')),
                'has_url_rewrite' => isset($rewrites[$id]),
            ];
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getProducts(array $skus, int $storeId = 0): array
    {
        $result = [];
        foreach ($skus as $sku) {
            $result[$sku] = ['exists' => false, 'enabled' => false, 'name' => ''];
        }
        if ($skus === []) {
            return $result;
        }
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)->addAttributeToSelect(['name', 'status'])->addFieldToFilter(
            'sku',
            ['in' => $skus]
        );
        foreach ($collection->getItems() as $product) {
            if (!$product instanceof DataObject) {
                continue;
            }
            $result[Cast::string($product->getData('sku'))] = [
                'exists' => true,
                'enabled' => Cast::int($product->getData('status')) === 1,
                'name' => Cast::string($product->getData('name')),
            ];
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getCmsPages(array $identifiers): array
    {
        $result = [];
        foreach ($identifiers as $identifier) {
            $result[$identifier] = ['exists' => false, 'active' => false, 'store_ids' => []];
        }
        foreach ($this->contentIndex->getRows(ContentLocator::TYPE_PAGE) as $row) {
            if (array_key_exists($row['identifier'], $result) && !$result[$row['identifier']]['active']) {
                $result[$row['identifier']] = [
                    'exists' => true,
                    'active' => $row['is_active'],
                    'store_ids' => $row['store_ids'],
                ];
            }
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getCategoryTree(int $rootId, int $depth, int $storeId = 0): array
    {
        $root = $this->categoryCollectionFactory->create()->addFieldToFilter('entity_id', $rootId)->getFirstItem();
        if (!$root instanceof DataObject || !$root->getData('entity_id')) {
            return [];
        }
        $rootPath = Cast::string($root->getData('path'));
        $rootLevel = Cast::int($root->getData('level'));

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'is_active', 'include_in_menu'])
            ->addFieldToFilter('path', ['like' => $rootPath . '/%'])
            ->addFieldToFilter('level', ['lteq' => $rootLevel + $depth])
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC');

        $nodes = [];
        $ids = [];
        foreach ($collection->getItems() as $category) {
            if ($category instanceof DataObject) {
                $ids[] = Cast::int($category->getData('entity_id'));
            }
        }
        $rewrites = $this->categoriesWithRewrite($ids, $storeId);
        foreach ($collection->getItems() as $category) {
            if (!$category instanceof DataObject) {
                continue;
            }
            $id = Cast::int($category->getData('entity_id'));
            $nodes[$id] = [
                'id' => $id,
                'parent_id' => Cast::int($category->getData('parent_id')),
                'name' => Cast::string($category->getData('name')),
                'active' => Cast::bool($category->getData('is_active')),
                'include_in_menu' => Cast::bool($category->getData('include_in_menu')),
                'has_url_rewrite' => isset($rewrites[$id]),
                'level' => Cast::int($category->getData('level')) - $rootLevel,
            ];
        }
        return $this->nest($nodes, $rootId);
    }

    /**
     * Nest.
     *
     * @param array $nodes
     * @param int $parentId
     * @return array
     * @phpstan-param array<int, array{id: int, parent_id: int, name: string, active: bool, include_in_menu: bool,
     *     has_url_rewrite: bool, level: int}> $nodes
     * @phpstan-return list<array{id: int, name: string, active: bool, include_in_menu: bool, has_url_rewrite: bool,
     *     level: int, children: list<mixed>}>
     */
    private function nest(array $nodes, int $parentId): array
    {
        $children = [];
        foreach ($nodes as $node) {
            if ($node['parent_id'] === $parentId) {
                $child = $node;
                unset($child['parent_id']);
                $child['children'] = $this->nest($nodes, $node['id']);
                $children[] = $child;
            }
        }
        return $children;
    }

    /**
     * Categories with rewrite.
     *
     * @param array $ids
     * @param int $storeId
     * @return array
     * @phpstan-param list<int> $ids
     * @phpstan-return array<int, true>
     */
    private function categoriesWithRewrite(array $ids, int $storeId): array
    {
        if ($ids === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('url_rewrite'), ['entity_id'])
            ->where('entity_type = ?', 'category')
            ->where('entity_id IN (?)', $ids);
        if ($storeId > 0) {
            $select->where('store_id = ?', $storeId);
        }
        $found = [];
        foreach ($connection->fetchCol($select) as $id) {
            $found[Cast::int($id)] = true;
        }
        return $found;
    }
}
