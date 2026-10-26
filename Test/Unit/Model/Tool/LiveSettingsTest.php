<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use PHPUnit\Framework\TestCase;

class LiveSettingsTest extends TestCase
{
    public function testAnInactiveEntityOnlyFlagsStorefrontFields(): void
    {
        $current = ['identifier' => 'sale', 'title' => 'Sale', 'is_active' => false, 'store_ids' => [1]];
        $changes = (new LiveSettings())->changes($current, [
            'title' => 'Big sale',
            'meta_title' => 'Sale',
            'is_active' => true,
            'store_ids' => [1],
            'identifier' => 'big-sale',
        ]);
        $this->assertSame(['is_active', 'identifier'], array_column($changes, 'field'), 'store_ids unchanged');
        $this->assertSame('Goes live on the storefront.', $changes[0]['effect']);
    }

    public function testEveryChangedFieldOfAnActiveEntityIsLive(): void
    {
        $current = ['identifier' => 'sale', 'title' => 'Sale', 'is_active' => true, 'store_ids' => [1, 2]];
        $changes = (new LiveSettings())->changes($current, [
            'title' => 'Big sale',
            'store_ids' => ['2', '1'],
            'is_active' => '1',
        ]);
        $this->assertSame(['title'], array_column($changes, 'field'), 'same store views and flag in another form');
    }

    public function testNoChangeNoConfirmation(): void
    {
        $this->assertSame([], (new LiveSettings())->changes(['is_active' => true, 'title' => 'A'], ['title' => 'A']));
    }
}
