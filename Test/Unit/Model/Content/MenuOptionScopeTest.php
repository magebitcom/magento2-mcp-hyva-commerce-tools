<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\MenuOptionScope;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

/**
 * Hyvä labels a menu option '%1 (ID: %2) %3' with the admin title first, so the title can hold "(ID: N)" itself.
 *
 * The caller reads store view 2 only.
 */
class MenuOptionScopeTest extends TestCase
{
    private const MENUS = [
        ['id' => 2, 'identifier' => 'footer', 'title' => 'Footer', 'store_ids' => [2]],
        ['id' => 5, 'identifier' => 'promo', 'title' => 'Promo (ID: 2)', 'store_ids' => [1]],
        ['id' => 6, 'identifier' => 'promo', 'title' => 'Promo', 'store_ids' => [2]],
        ['id' => 7, 'identifier' => 'sale', 'title' => 'Sale', 'store_ids' => [2]],
        ['id' => 8, 'identifier' => 'sale', 'title' => 'Sale (ID: 7)', 'store_ids' => [2]],
        ['id' => 9, 'identifier' => 'news', 'title' => 'News & Deals', 'store_ids' => [2]],
    ];

    /**
     * Menu 5 (store view 1) is titled "Promo (ID: 2)": its label names readable menu 2 first.
     *
     * @return void
     */
    public function testAnIdInTheTitleDoesNotNameTheMenu(): void
    {
        $result = $this->scope()->filter([
            ['value' => 'promo', 'label' => 'Promo (ID: 2) (ID: 5) '],
            ['value' => 'promo', 'label' => 'Promo (ID: 6) '],
        ]);

        $this->assertSame([['value' => 'promo', 'label' => 'Promo (ID: 6) ']], $result['options']);
        $this->assertSame(1, $result['hidden']);
    }

    /**
     * Menu 8 "Sale (ID: 7)" and menu 7 "Sale" share the identifier: both titles start the label of menu 8.
     *
     * @return void
     */
    public function testALabelTwoMenusCouldOwnIsHidden(): void
    {
        $result = $this->scope()->filter([
            ['value' => 'sale', 'label' => 'Sale (ID: 7) (ID: 8) '],
            ['value' => 'sale', 'label' => 'Sale (ID: 7) '],
        ]);

        $this->assertSame([['value' => 'sale', 'label' => 'Sale (ID: 7) ']], $result['options']);
    }

    /**
     * A label no menu's title starts is hidden; an HTML-escaped title still matches.
     *
     * @return void
     */
    public function testTheTitleMustStartTheLabel(): void
    {
        $result = $this->scope()->filter([
            ['value' => '', 'label' => 'Please select a menu'],
            ['value' => 'footer', 'label' => 'Renamed (ID: 2) '],
            ['value' => 'news', 'label' => 'News &amp; Deals (ID: 9) (Disabled)'],
            ['value' => 'footer', 'label' => 'Fusszeile'],
        ]);

        $this->assertSame(['', 'news', 'footer'], array_column($result['options'], 'value'));
        $this->assertSame(1, $result['hidden']);
    }

    /**
     * Scope over the menus for a caller who reads store view 2.
     *
     * @return MenuOptionScope
     */
    private function scope(): MenuOptionScope
    {
        $scopeFilter = $this->createMock(ContentScopeFilter::class);
        $scopeFilter->method('isRestricted')->willReturn(true);
        $scopeFilter->method('viewableTypes')->willReturnArgument(0);
        $scopeFilter->method('allowsStores')
            ->willReturnCallback(static fn (array $storeIds): bool => in_array(2, $storeIds, true));
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturn(self::MENUS);
        return new MenuOptionScope($scopeFilter, $gateway, new Escaper());
    }
}
