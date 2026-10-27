<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Menu;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Menu\LinkTargetResolver;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * CMS page link checks read only the linked identifiers, never the page content.
 */
class LinkTargetResolverTest extends TestCase
{
    public function testCmsPagesAreLookedUpByIdentifierWithoutContent(): void
    {
        $index = $this->createMock(ContentIndex::class);
        $index->expects($this->never())->method('getRows');
        $index->expects($this->once())->method('getByIdentifiers')->with('cms_page', ['about', 'gone'])->willReturn([
            ['id' => 1, 'identifier' => 'about', 'is_active' => false, 'store_ids' => [2]],
            ['id' => 4, 'identifier' => 'about', 'is_active' => true, 'store_ids' => [1]],
            ['id' => 5, 'identifier' => 'about', 'is_active' => false, 'store_ids' => [3]],
        ]);
        $resolver = new LinkTargetResolver(
            $this->createMock(CategoryCollectionFactory::class),
            $this->createMock(ProductCollectionFactory::class),
            $index,
            $this->createMock(ResourceConnection::class)
        );

        $this->assertSame([
            'about' => ['exists' => true, 'active' => true, 'store_ids' => [1]],
            'gone' => ['exists' => false, 'active' => false, 'store_ids' => []],
        ], $resolver->getCmsPages(['about', 'gone']));
    }
}
