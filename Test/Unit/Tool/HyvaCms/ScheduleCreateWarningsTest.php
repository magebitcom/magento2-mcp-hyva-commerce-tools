<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuScheduleCreate;
use PHPUnit\Framework\TestCase;

/**
 * T-138: a release activates a scheduled menu when it fires (T-93), so the reply warns when the menu is inactive.
 */
class ScheduleCreateWarningsTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<ScheduleCreate>, 1: string, 2: bool, 3: bool}>
     */
    public static function entityProvider(): array
    {
        return [
            'inactive menu, menu tool' => [MenuScheduleCreate::class, 'menu', false, true],
            'inactive menu, generic tool' => [ScheduleCreate::class, 'menu', false, true],
            'active menu' => [MenuScheduleCreate::class, 'menu', true, false],
            'inactive page' => [ScheduleCreate::class, 'cms_page', false, false],
            'inactive block' => [ScheduleCreate::class, 'cms_block', false, false],
        ];
    }

    /**
     * @dataProvider entityProvider
     * @param class-string<ScheduleCreate> $class
     * @param string $type
     * @param bool $active
     * @param bool $warned
     */
    public function testTheReplyWarnsThatTheReleaseActivatesAnInactiveMenu(
        string $class,
        string $type,
        bool $active,
        bool $warned
    ): void {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locateForWrite')->willReturn([
            'type' => $type, 'id' => 4, 'identifier' => 'main', 'title' => 'Main', 'is_active' => $active,
            'store_ids' => [0],
        ]);
        $schedule = $this->createMock(ScheduleService::class);
        $schedule->method('schedule')
            ->willReturn(['release_id' => 9, 'item_id' => 11, 'release_created' => false, 'reactivated' => false]);
        $conflicts = $this->createMock(ConflictReport::class);
        $conflicts->method('getConflictsWithinCallerScope')->willReturn(['conflicts' => [], 'hidden' => 0]);
        $tool = new $class(
            $locator,
            $schedule,
            $this->createMock(ScheduleTime::class),
            $conflicts,
            $this->createMock(ContentAcl::class)
        );

        $result = $tool->execute(['entity_type' => $type, 'id' => 4, 'release_id' => 9]);
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = Cast::stringKeyed(json_decode(is_string($text) ? $text : '', true));

        $this->assertFalse($result->isError());
        $warnings = Cast::array($payload['warnings'] ?? null);
        if (!$warned) {
            $this->assertSame([], $warnings);
            return;
        }
        $this->assertCount(1, $warnings);
        $warning = Cast::string($warnings[0] ?? '');
        $this->assertStringContainsString('inactive', $warning);
        $this->assertStringContainsString('activates', $warning);
    }
}
