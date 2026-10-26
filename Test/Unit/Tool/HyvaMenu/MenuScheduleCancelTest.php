<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMenu;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
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

    public function testReleasesOfContentOutsideTheRolesScopeAreNotCancelled(): void
    {
        $callback = $this->cancelCallback(ScheduleCancel::class);
        $this->assertTrue($callback('cms_page', 1));
        $this->assertFalse($callback('cms_page', 2));
    }

    /**
     * The per-item permission callback the tool hands to ScheduleService::cancel().
     *
     * @param class-string<ScheduleCancel> $class
     * @return array<string, bool>
     */
    private function mayCancel(string $class): array
    {
        $callback = $this->cancelCallback($class);
        return ['menu' => (bool) $callback('menu', 1), 'cms_page' => (bool) $callback('cms_page', 1)];
    }

    /**
     * The callback itself; the caller may change entity 1 only.
     *
     * @param class-string<ScheduleCancel> $class
     * @return callable
     * @phpstan-return callable(string, int): bool
     */
    private function cancelCallback(string $class): callable
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
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('allowsWrite')->willReturnCallback(static fn (string $type, int $id): bool => $id === 1);
        (new $class($service, $acl, $locator))->execute(['item_ids' => [1]]);
        $this->assertIsCallable($callback);
        return static fn (string $type, int $id): bool => (bool) $callback($type, $id);
    }
}
