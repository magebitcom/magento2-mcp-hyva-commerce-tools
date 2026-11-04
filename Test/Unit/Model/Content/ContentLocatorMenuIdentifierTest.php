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
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * T-149: a menu identifier shared by several store views is not resolved to one of them without store_id.
 */
class ContentLocatorMenuIdentifierTest extends TestCase
{
    use StoreScopeStub;

    /**
     * Menus by id: 1 = "main" in store view 1, 2 = "main" in store view 2, 3 = "footer" in all store views,
     * 4 = "main_extra" (matched by the substring search, not by the identifier).
     */
    private const MENUS = [
        1 => ['id' => 1, 'identifier' => 'main', 'title' => 'Main EN', 'is_active' => true, 'store_ids' => [1]],
        2 => ['id' => 2, 'identifier' => 'main', 'title' => 'Main DE', 'is_active' => true, 'store_ids' => [2]],
        3 => ['id' => 3, 'identifier' => 'footer', 'title' => 'Footer', 'is_active' => true, 'store_ids' => [0]],
        4 => ['id' => 4, 'identifier' => 'main_extra', 'title' => 'Extra', 'is_active' => true, 'store_ids' => [1]],
    ];

    public function testASharedIdentifierWithoutStoreIdNamesTheCandidates(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Several menus use identifier "main" (id 1 in store views 1, id 2 in store views 2). Pass id or store_id.'
        );

        $this->locator(null)->locate(ContentLocator::TYPE_MENU, ['identifier' => 'main']);
    }

    public function testAUniqueIdentifierResolvesWithoutStoreId(): void
    {
        $this->assertSame(
            3,
            $this->locator(null)->locate(ContentLocator::TYPE_MENU, ['identifier' => 'footer'])['id']
        );
    }

    public function testStoreIdPicksTheStoreViewsMenu(): void
    {
        $this->assertSame(
            2,
            $this->locator(null)->locate(ContentLocator::TYPE_MENU, ['identifier' => 'main', 'store_id' => 2])['id']
        );
    }

    public function testARestrictedRoleOnlyCompetesAmongTheMenusItMaySee(): void
    {
        $this->assertSame(2, $this->locator([2])->locate(ContentLocator::TYPE_MENU, ['identifier' => 'main'])['id']);
    }

    public function testAnUnknownIdentifierDoesNotExist(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Menu "missing" does not exist.');

        $this->locator(null)->locate(ContentLocator::TYPE_MENU, ['identifier' => 'missing']);
    }

    /**
     * Locator over the menus, Menu Builder enabled.
     *
     * @param list<int>|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @return ContentLocator
     */
    private function locator(?array $allowedStoreIds): ContentLocator
    {
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturnCallback(static fn (array $filters): array => array_values(array_filter(
            self::MENUS,
            static fn (array $menu): bool => str_contains($menu['identifier'], (string) ($filters['search'] ?? ''))
        )));
        $gateway->method('get')->willReturnCallback(static fn (int $id): array => self::MENUS[$id]);
        $gateway->method('findIdByIdentifier')->willReturnCallback(
            static function (string $identifier, ?int $storeId): ?int {
                foreach (self::MENUS as $id => $menu) {
                    if ($menu['identifier'] === $identifier && in_array($storeId, $menu['store_ids'], true)) {
                        return $id;
                    }
                }
                return null;
            }
        );
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $features->method('isInstalledAndEnabled')->willReturn(true);

        return new ContentLocator(
            $this->createMock(PageRepositoryInterface::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(PageCollectionFactory::class),
            $this->createMock(BlockCollectionFactory::class),
            $gateway,
            $features,
            $this->storeScope($allowedStoreIds),
            $this->createMock(ContentIndex::class)
        );
    }
}
