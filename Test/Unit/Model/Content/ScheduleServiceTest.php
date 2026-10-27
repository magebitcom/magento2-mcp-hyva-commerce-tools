<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Provider as ContentProvider;
use Hyva\CmsScheduling\Api\Data\ScheduledItemInterface;
use Hyva\CmsScheduling\Api\Data\ScheduledItemInterfaceFactory;
use Hyva\CmsScheduling\Api\Data\ScheduledReleaseInterfaceFactory;
use Hyva\CmsScheduling\Api\ScheduledItemRepositoryInterface;
use Hyva\CmsScheduling\Api\ScheduledReleaseRepositoryInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Rakit\Validation\Validator;

class ScheduleServiceTest extends TestCase
{
    public function testLimitKeepsTheNewestRowsAndReturnsThemOldestFirst(): void
    {
        $orders = [];
        $limits = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnCallback(function (array $order) use ($select, &$orders): Select {
            $orders = $order;
            return $select;
        });
        $select->method('limit')->willReturnCallback(function (int $count) use ($select, &$limits): Select {
            $limits[] = $count;
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $row = static fn (int $id): array => [
            'item_id' => $id, 'release_id' => $id, 'entity_type' => 'cms_page', 'entity_id' => 1, 'name' => 'N',
            'status' => 'success', 'settings' => null, 'version_history_id' => null, 'creation_time' => '',
            'release_name' => 'R', 'release_status' => 'success', 'time_scheduled' => '',
        ];
        // The database returns newest first under the DESC order.
        $connection->method('fetchAll')->willReturn([$row(3), $row(2)]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $service = new ScheduleService(
            $resource,
            $this->createMock(ScheduledReleaseRepositoryInterface::class),
            $this->createMock(ScheduledItemRepositoryInterface::class),
            $this->createMock(ScheduledReleaseInterfaceFactory::class),
            $this->createMock(ScheduledItemInterfaceFactory::class),
            $this->createMock(ContentProvider::class),
            $this->createMock(ContentService::class),
            new Validator()
        );

        $items = $service->getItems(['status' => 'any', 'limit' => 2]);

        $this->assertSame(['r.time_scheduled DESC', 'r.id DESC', 'i.id DESC'], $orders);
        $this->assertSame([2], $limits);
        $this->assertSame([2, 3], array_column($items, 'item_id'));
    }

    /**
     * @dataProvider invalidSettingsProvider
     * @param array<string, mixed> $settings
     * @param string $message
     */
    public function testInvalidSettingsAreRejectedBeforeAnythingIsStored(array $settings, string $message): void
    {
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('isSchedulingAllowed')->willReturn(true);
        $provider->method('getScheduleValidationRules')->willReturn([
            'is_active' => 'required|boolean',
            'title' => 'required|max:255',
            'content_heading' => 'max:255',
        ]);
        $content = $this->createMock(ContentService::class);
        $document = ['content' => ['a' => ['uid' => 'a', 'component' => 'row', 'children' => []]]];
        $content->method('getWorkingDocument')->willReturn($document);
        $content->method('stamp')->willReturn($document);
        $releaseFactory = $this->createMock(ScheduledReleaseInterfaceFactory::class);
        $releaseFactory->expects($this->never())->method('create');
        $itemFactory = $this->createMock(ScheduledItemInterfaceFactory::class);
        $itemFactory->expects($this->never())->method('create');

        $service = new ScheduleService(
            $this->createMock(ResourceConnection::class),
            $this->createMock(ScheduledReleaseRepositoryInterface::class),
            $this->createMock(ScheduledItemRepositoryInterface::class),
            $releaseFactory,
            $itemFactory,
            $provider,
            $content,
            new Validator()
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches($message);
        $service->schedule(
            'cms_page',
            5,
            'Page',
            ['release_name' => 'R', 'time_utc' => '2099-01-01 10:00:00'],
            null,
            $settings
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidSettingsProvider(): array
    {
        return [
            'unknown key' => [['is_active' => true, 'title' => 'T', 'identifier' => 'x'], '/cannot be scheduled/'],
            'missing required title' => [['is_active' => true], '/Invalid settings/'],
            'wrong type' => [['is_active' => 'yes', 'title' => 'T'], '/Invalid settings/'],
        ];
    }

    public function testCancelRefusesTheWholeBatchWhenOneItemTypeIsNotAllowed(): void
    {
        $items = [];
        foreach ([1 => 'cms_page', 2 => 'menu'] as $id => $type) {
            $item = $this->createMock(ScheduledItemInterface::class);
            $item->method('getEntityType')->willReturn($type);
            $item->method('getStatus')->willReturn(ScheduledItemInterface::STATUS_PENDING);
            $items[$id] = $item;
        }
        $itemRepository = $this->createMock(ScheduledItemRepositoryInterface::class);
        $itemRepository->method('get')->willReturnCallback(static fn (int $id) => $items[$id]);
        $itemRepository->expects($this->never())->method('save');
        $service = new ScheduleService(
            $this->createMock(ResourceConnection::class),
            $this->createMock(ScheduledReleaseRepositoryInterface::class),
            $itemRepository,
            $this->createMock(ScheduledReleaseInterfaceFactory::class),
            $this->createMock(ScheduledItemInterfaceFactory::class),
            $this->createMock(ContentProvider::class),
            $this->createMock(ContentService::class),
            new Validator()
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/Nothing was cancelled.*#2 \(menu\)/');
        $service->cancel([1, 2], null, static fn (string $type): bool => $type === 'cms_page');
    }
}
