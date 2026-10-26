<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;

/**
 * Real ContentScopeFilter and ContentLocator over mocked store view assignments (store views 1 and 2 exist).
 */
trait ContentScopeStub
{
    use StoreScopeStub;

    /**
     * Filter for a caller restricted to the store views, or unrestricted (null).
     *
     * @param array|null $allowedStoreIds
     * @param array $storeIds Store views per entity type and id; entities not listed do not exist.
     * @param ContentIndex|null $contentIndex Replaces the lookup over $storeIds (e.g. to count queries).
     * @return ContentScopeFilter
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-param array<string, array<int, list<int>>> $storeIds
     */
    private function contentScope(
        ?array $allowedStoreIds,
        array $storeIds = [],
        ?ContentIndex $contentIndex = null
    ): ContentScopeFilter {
        if ($contentIndex === null) {
            $contentIndex = $this->createMock(ContentIndex::class);
            $contentIndex->method('getStoreIds')->willReturnCallback(
                static fn (string $type, array $ids): array => array_intersect_key(
                    $storeIds[$type] ?? [],
                    array_flip($ids)
                )
            );
        }
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $guard = $this->storeScope($allowedStoreIds);
        $locator = new ContentLocator(
            $this->createMock(PageRepositoryInterface::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(PageCollectionFactory::class),
            $this->createMock(BlockCollectionFactory::class),
            $this->createMock(MenuGatewayInterface::class),
            $features,
            $guard,
            $contentIndex
        );
        return new ContentScopeFilter($locator, $guard);
    }
}
