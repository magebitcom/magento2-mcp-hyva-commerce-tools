<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCancel;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuScheduleCancel;
use PHPUnit\Framework\TestCase;

class MenuScheduleCancelTest extends TestCase
{
    public function testTheMenuVariantOnlyCancelsMenus(): void
    {
        $this->assertSame(['menu' => true, 'cms_page' => false], $this->mayCancel(MenuScheduleCancel::class));
        $this->assertSame(['menu' => true, 'cms_page' => true], $this->mayCancel(ScheduleCancel::class));
    }

    /**
     * The per-item permission callback the tool hands to ScheduleService::cancel().
     *
     * @param class-string<ScheduleCancel> $class
     * @return array<string, bool>
     */
    private function mayCancel(string $class): array
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        $callback = null;
        $service = $this->createMock(ScheduleService::class);
        $service->method('cancel')->willReturnCallback(
            static function (array $items, ?int $release, callable $mayCancel) use (&$callback): array {
                $callback = $mayCancel;
                return ['cancelled_items' => [], 'cancelled_releases' => []];
            }
        );
        (new $class($service, $acl))->execute(['item_ids' => [1]]);
        $this->assertIsCallable($callback);
        return ['menu' => (bool) $callback('menu'), 'cms_page' => (bool) $callback('cms_page')];
    }
}
