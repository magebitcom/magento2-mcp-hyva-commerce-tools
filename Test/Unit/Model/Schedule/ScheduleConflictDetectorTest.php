<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Schedule;

use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleConflictDetector;
use PHPUnit\Framework\TestCase;

class ScheduleConflictDetectorTest extends TestCase
{
    private const NOW = 1790000000;

    /**
     * @var ScheduleConflictDetector
     */
    private ScheduleConflictDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ScheduleConflictDetector();
    }

    public function testSameEntityWithinWindowIsAnError(): void
    {
        $releases = [1 => $this->release(1, 'A', 3600), 2 => $this->release(2, 'B', 3600 + 1800)];
        $items = [$this->item(10, 1, 'cms_block', 5), $this->item(11, 2, 'cms_block', 5)];
        $codes = $this->codes(
            $this->detector->detect($items, $releases, ['cms_block:5' => ['exists' => true]], self::NOW)
        );

        $this->assertSame('error', $codes['overlapping_schedule']);
        $this->assertSame('info', $codes['no_fallback']);
        $this->assertArrayNotHasKey('superseded_schedule', $codes);
    }

    public function testSameEntityFarApartIsSuperseded(): void
    {
        $releases = [1 => $this->release(1, 'A', 3600), 2 => $this->release(2, 'B', 3 * 86400)];
        $items = [$this->item(10, 1, 'cms_page', 2), $this->item(11, 2, 'cms_page', 2)];
        $conflicts = $this->detector->detect($items, $releases, ['cms_page:2' => ['exists' => true]], self::NOW);

        $codes = $this->codes($conflicts);
        $this->assertSame('warning', $codes['superseded_schedule']);
        $fallback = array_values(array_filter($conflicts, static fn (array $c): bool => $c['code'] === 'no_fallback'));
        $this->assertSame([2], $fallback[0]['release_ids'], 'Only the last release lacks a fallback.');
    }

    public function testOverdueEmptyAndMissingEntity(): void
    {
        $releases = [1 => $this->release(1, 'Late', -3600), 2 => $this->release(2, 'Empty', 7200)];
        $items = [$this->item(10, 1, 'cms_page', 99)];
        $codes = $this->codes(
            $this->detector->detect($items, $releases, ['cms_page:99' => ['exists' => false]], self::NOW)
        );

        $this->assertSame('error', $codes['overdue']);
        $this->assertSame('warning', $codes['empty_release']);
        $this->assertSame('error', $codes['missing_entity']);
    }

    public function testRecentlyDueReleaseIsNotOverdueYet(): void
    {
        $releases = [1 => $this->release(1, 'Just due', -60)];
        $codes = $this->codes($this->detector->detect([$this->item(1, 1, 'cms_page', 1)], $releases, [], self::NOW));
        $this->assertArrayNotHasKey('overdue', $codes);
    }

    public function testStoreViewCollisionBetweenDifferentEntities(): void
    {
        $releases = [1 => $this->release(1, 'Home A', 3600), 2 => $this->release(2, 'Home B', 3600 + 600)];
        $items = [$this->item(10, 1, 'cms_page', 2), $this->item(11, 2, 'cms_page', 7)];
        $entities = [
            'cms_page:2' => ['exists' => true, 'identifier' => 'home', 'store_ids' => [1]],
            'cms_page:7' => ['exists' => true, 'identifier' => 'home', 'store_ids' => [0]],
        ];
        $codes = $this->codes($this->detector->detect($items, $releases, $entities, self::NOW));
        $this->assertSame('error', $codes['store_view_collision']);
    }

    public function testErrorsAreSortedFirst(): void
    {
        $releases = [1 => $this->release(1, 'A', 3600), 2 => $this->release(2, 'B', 3700)];
        $items = [$this->item(1, 1, 'cms_block', 1), $this->item(2, 2, 'cms_block', 1)];
        $conflicts = $this->detector->detect($items, $releases, [], self::NOW);
        $this->assertSame('error', $conflicts[0]['severity']);
        $this->assertSame('info', $conflicts[count($conflicts) - 1]['severity']);
    }

    /**
     * Release.
     *
     * @param int $id
     * @param string $name
     * @param int $offset Seconds from NOW.
     * @return array
     * @phpstan-return array{release_id: int, name: string, time_scheduled: string, status: string}
     */
    private function release(int $id, string $name, int $offset): array
    {
        return [
            'release_id' => $id,
            'name' => $name,
            'time_scheduled' => gmdate('Y-m-d H:i:s', self::NOW + $offset),
            'status' => 'pending',
        ];
    }

    /**
     * Item.
     *
     * @param int $itemId
     * @param int $releaseId
     * @param string $type
     * @param int $entityId
     * @return array
     * @phpstan-return array{item_id: int, release_id: int, entity_type: string, entity_id: int}
     */
    private function item(int $itemId, int $releaseId, string $type, int $entityId): array
    {
        return ['item_id' => $itemId, 'release_id' => $releaseId, 'entity_type' => $type, 'entity_id' => $entityId];
    }

    /**
     * Codes.
     *
     * @param array $conflicts
     * @return array code => severity
     * @phpstan-param list<array{severity: string, code: string}> $conflicts
     * @phpstan-return array<string, string>
     */
    private function codes(array $conflicts): array
    {
        $codes = [];
        foreach ($conflicts as $conflict) {
            $codes[$conflict['code']] = $conflict['severity'];
        }
        return $codes;
    }
}
