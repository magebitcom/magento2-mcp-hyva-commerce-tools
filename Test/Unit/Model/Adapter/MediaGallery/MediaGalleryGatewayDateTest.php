<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\MediaGallery;

use Hyva\MediaGallery\Model\Asset\SearchAssetsList;
use Hyva\MediaGallery\Model\MediaMover;
use Hyva\MediaGallery\Model\Trash\TrashRepository;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Adapter\MediaGallery\MediaGalleryGateway;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Filesystem;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\MediaGalleryApi\Api\CreateDirectoriesByPathsInterface;
use Magento\MediaGalleryApi\Api\Data\AssetInterface;
use Magento\MediaGalleryApi\Api\GetAssetsByIdsInterface;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;
use Magento\MediaGalleryApi\Api\GetAssetsKeywordsInterface;
use Magento\MediaGalleryApi\Api\IsPathExcludedInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every media date leaves the gateway as UTC "Y-m-d H:i:s", whatever the store timezone and locale.
 */
class MediaGalleryGatewayDateTest extends TestCase
{
    /**
     * @var SearchAssetsList&MockObject
     */
    private SearchAssetsList&MockObject $searchAssetsList;

    /**
     * @var TrashRepository&MockObject
     */
    private TrashRepository&MockObject $trashRepository;

    /**
     * @var GetAssetsByIdsInterface&MockObject
     */
    private GetAssetsByIdsInterface&MockObject $getAssetsByIds;

    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface&MockObject $connection;

    /**
     * @var MediaGalleryGateway
     */
    private MediaGalleryGateway $gateway;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->searchAssetsList = $this->createMock(SearchAssetsList::class);
        $this->trashRepository = $this->createMock(TrashRepository::class);
        $this->getAssetsByIds = $this->createMock(GetAssetsByIdsInterface::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('America/Los_Angeles');
        $locale = $this->createMock(ResolverInterface::class);
        $locale->method('getLocale')->willReturn('en_US');
        $this->gateway = new MediaGalleryGateway(
            $this->searchAssetsList,
            $this->createMock(MediaMover::class),
            $this->trashRepository,
            $this->getAssetsByIds,
            $this->createMock(GetAssetsByPathsInterface::class),
            $this->createMock(GetAssetsKeywordsInterface::class),
            $this->createMock(IsPathExcludedInterface::class),
            $this->createMock(CreateDirectoriesByPathsInterface::class),
            $resource,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(Filesystem::class),
            $this->createMock(LoggerInterface::class),
            $timezone,
            $locale
        );
    }

    /**
     * Hyvä's trash list gives a locale string in store time; the reply carries UTC.
     *
     * @return void
     */
    public function testTrashListDatesAreUtcSqlFormat(): void
    {
        $this->trashRepository->method('getList')->willReturn([
            'total_count' => 2,
            'items' => [
                ['id' => 1, 'trashed_at' => 'Nov 3, 2026, 12:10 PM'],
                ['id' => 2, 'trashed_at' => "Nov 3, 2026, 12:10\u{202F}PM"],
            ],
        ]);

        $items = $this->gateway->trashList(null, 1, 20)['items'];

        $this->assertSame(['2026-11-03 20:10:00', '2026-11-03 20:10:00'], array_column($items, 'trashed_at'));
    }

    /**
     * Trash rows read by id are DB values (UTC) and stay as they are.
     *
     * @return void
     */
    public function testTrashRowsByIdKeepTheDbValue(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([['id' => '4', 'trashed_at' => '2026-11-03 20:11:09']]);

        $items = $this->gateway->trashList(null, 1, 20, [4])['items'];

        $this->assertSame('2026-11-03 20:11:09', $items[0]['trashed_at'] ?? null);
    }

    /**
     * Hyvä's asset search gives updated_at as a locale string in store time; the reply carries UTC.
     *
     * @return void
     */
    public function testSearchUpdatedAtIsUtcSqlFormat(): void
    {
        $this->searchAssetsList->method('execute')->willReturn([
            'total_count' => 1,
            'items' => [['id' => 3, 'path' => 'wysiwyg/a.jpg', 'updated_at' => 'Nov 3, 2026, 12:10 PM']],
        ]);

        $items = $this->gateway->search([])['items'];

        $this->assertSame('2026-11-03 20:10:00', $items[0]['updated_at'] ?? null);
    }

    /**
     * Asset rows built from the DB value keep it.
     *
     * @return void
     */
    public function testRawRowDatesUnchanged(): void
    {
        $asset = $this->createMock(AssetInterface::class);
        $asset->method('getId')->willReturn(3);
        $asset->method('getPath')->willReturn('wysiwyg/a.jpg');
        $asset->method('getContentType')->willReturn('image/jpeg');
        $asset->method('getUpdatedAt')->willReturn('2026-11-03 20:11:09');
        $this->getAssetsByIds->method('execute')->willReturn([$asset]);

        $this->assertSame('2026-11-03 20:11:09', $this->gateway->getByIds([3])[0]['updated_at']);
    }

    /**
     * Empty input gives null; a string that cannot be parsed is returned as it is, without an exception.
     *
     * @return void
     */
    public function testNormalizeDateEdgeCases(): void
    {
        $this->assertNull($this->gateway->normalizeDate(null));
        $this->assertNull($this->gateway->normalizeDate(''));
        $this->assertSame('not a date', $this->gateway->normalizeDate('not a date'));
        $this->assertSame('2026-11-03 20:11:09', $this->gateway->normalizeDate('2026-11-03 20:11:09'));
    }
}
