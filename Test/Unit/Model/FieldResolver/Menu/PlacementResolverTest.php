<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\FieldResolver\Menu;

use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu\PlacementResolver;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use PHPUnit\Framework\TestCase;

/**
 * Design config scopes a restricted role may not open are left out of the placement field and counted.
 */
class PlacementResolverTest extends TestCase
{
    private const DEFAULT_ROW = ['location' => 'header', 'path' => 'p', 'scope' => 'default', 'scope_id' => 0];
    private const STORE_ROW = ['location' => 'header', 'path' => 'p', 'scope' => 'stores', 'scope_id' => 1];

    public function testHiddenAssignmentsAreLeftOutAndCounted(): void
    {
        $menus = $this->createMock(MenuService::class);
        $menus->method('getAssignments')->with('main')->willReturn([self::DEFAULT_ROW, self::STORE_ROW]);
        $menus->expects($this->once())->method('filterAssignments')
            ->with([self::DEFAULT_ROW, self::STORE_ROW])
            ->willReturn(['visible' => [self::STORE_ROW], 'hidden' => 1]);

        $placement = (new PlacementResolver($menus, $this->createMock(ScheduleService::class)))->resolve(
            ['type' => 'menu', 'id' => 7, 'identifier' => 'main', 'title' => '', 'is_active' => true,
                'store_ids' => [1]],
            []
        );

        $this->assertIsArray($placement);
        $this->assertSame([self::STORE_ROW], $placement['design_config']);
        $this->assertSame(1, $placement['design_config_hidden_by_permissions']);
    }
}
