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

    public function testPreviewUrlKeyIsNeverLive(): void
    {
        $current = ['is_active' => true, 'title' => 'Main', 'preview_url_key' => 'home'];
        $this->assertSame([], (new LiveSettings())->changes($current, ['preview_url_key' => 'contact']));
    }

    public function testNoChangeNoConfirmation(): void
    {
        $this->assertSame([], (new LiveSettings())->changes(['is_active' => true, 'title' => 'A'], ['title' => 'A']));
    }

    /**
     * T-134 / T-139: activating an unpublished menu renders nothing until it is published and placed.
     */
    public function testActivatingAnUnpublishedMenuSaysItNeedsPublishingAndPlacing(): void
    {
        $settings = new LiveSettings();
        $changes = $settings->menuChanges(['is_active' => false, 'is_published' => false], ['is_active' => true]);

        $this->assertSame(['is_active'], array_column($changes, 'field'));
        $this->assertSame(LiveSettings::MENU_ACTIVATION_EFFECT, $changes[0]['effect']);
        $this->assertStringContainsString('hyva_menu_publish', LiveSettings::MENU_ACTIVATION_EFFECT);
        $this->assertStringContainsString('renders nothing', LiveSettings::MENU_ACTIVATION_EFFECT);
        // I1 (review lap 3): one placement phrase, shared with the schedule warning.
        $this->assertStringContainsString(LiveSettings::MENU_PLACEMENT, LiveSettings::MENU_ACTIVATION_EFFECT);
    }

    public function testActivatingAPublishedMenuOrDeactivatingKeepsTheGeneralWording(): void
    {
        $settings = new LiveSettings();
        $published = $settings->menuChanges(['is_active' => false, 'is_published' => true], ['is_active' => true]);
        $off = $settings->menuChanges(['is_active' => true, 'is_published' => false], ['is_active' => false]);

        $this->assertSame('Goes live on the storefront.', $published[0]['effect']);
        $this->assertSame('Goes offline on the storefront.', $off[0]['effect']);
    }
}
