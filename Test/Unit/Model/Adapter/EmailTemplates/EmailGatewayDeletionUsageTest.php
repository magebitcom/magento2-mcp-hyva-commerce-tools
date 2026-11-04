<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\EmailTemplates;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Adapter\EmailTemplates\EmailGateway;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Magento\Email\Model\ResourceModel\Template as EmailResource;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as EmailCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Newsletter\Model\ResourceModel\Template as NewsletterResource;
use Magento\Newsletter\Model\ResourceModel\Template\CollectionFactory as NewsletterCollectionFactory;
use Magento\Newsletter\Model\TemplateFactory as NewsletterTemplateFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-107: what counts as "in use" for the email and newsletter template delete tools.
 */
class EmailGatewayDeletionUsageTest extends TestCase
{
    /**
     * Review focus: a template a store config path sends (e.g. sales_email/order/template), at any scope, is in use.
     */
    public function testAnEmailTemplateSentByAStoreConfigPathIsInUse(): void
    {
        $select = $this->select();
        $wheres = [];
        $select->method('where')->willReturnCallback(
            static function (string $condition, mixed $value) use ($select, &$wheres): Select {
                $wheres[$condition] = $value;
                return $select;
            }
        );
        $usage = $this->gateway($select, [
            ['path' => 'sales_email/order/template', 'scope' => 'default', 'scope_id' => '0'],
            ['path' => 'sales_email/order/guest_template', 'scope' => 'stores', 'scope_id' => '1'],
            // Matched by LIKE '%template' but not an email template field.
            ['path' => 'some/section/subtemplate', 'scope' => 'default', 'scope_id' => '0'],
        ])->deletionUsage(EmailGatewayInterface::TYPE_EMAIL, 12);

        $this->assertSame(['value = ?' => '12', 'path LIKE ?' => '%template'], $wheres);
        $this->assertSame([
            'used' => true,
            'total' => 2,
            'store_config' => [
                ['path' => 'sales_email/order/template', 'scope' => 'default', 'scope_id' => 0],
                ['path' => 'sales_email/order/guest_template', 'scope' => 'stores', 'scope_id' => 1],
            ],
        ], $usage);
    }

    public function testAnEmailTemplateNoConfigPathSendsIsNotInUse(): void
    {
        $usage = $this->gateway($this->select(true), [])->deletionUsage(EmailGatewayInterface::TYPE_EMAIL, 12);

        $this->assertSame(['used' => false, 'total' => 0, 'store_config' => []], $usage);
    }

    /**
     * Not sent, sending and paused queues still send; sent and cancelled ones are history (only counted).
     */
    public function testOnlyUnsentNewsletterQueuesAreUsage(): void
    {
        $usage = $this->gateway($this->select(true), [
            ['queue_id' => '1', 'queue_status' => '3', 'queue_start_at' => '2026-10-01 08:00:00'],
            ['queue_id' => '2', 'queue_status' => '0', 'queue_start_at' => '2026-11-10 08:00:00'],
            ['queue_id' => '3', 'queue_status' => '2', 'queue_start_at' => null],
            ['queue_id' => '4', 'queue_status' => '4', 'queue_start_at' => null],
            ['queue_id' => '5', 'queue_status' => '1', 'queue_start_at' => '2026-11-04 08:00:00'],
        ])->deletionUsage(EmailGatewayInterface::TYPE_NEWSLETTER, 7);

        $this->assertSame([
            'used' => true,
            'total' => 3,
            'queue' => [
                ['queue_id' => 2, 'status' => 'not_sent', 'start_at' => '2026-11-10 08:00:00'],
                ['queue_id' => 4, 'status' => 'paused', 'start_at' => null],
                ['queue_id' => 5, 'status' => 'sending', 'start_at' => '2026-11-04 08:00:00'],
            ],
            'queue_history' => 2,
        ], $usage);
    }

    public function testANewsletterWithOnlySentQueuesIsNotInUse(): void
    {
        $usage = $this->gateway($this->select(true), [
            ['queue_id' => '1', 'queue_status' => '3', 'queue_start_at' => null],
        ])->deletionUsage(EmailGatewayInterface::TYPE_NEWSLETTER, 7);

        $this->assertFalse($usage['used']);
        $this->assertSame([], $usage['queue'] ?? null);
        $this->assertSame(1, $usage['queue_history'] ?? null);
    }

    /**
     * Fluent select mock.
     *
     * @param bool $withWhere Whether where() returns the select (false: the test stubs it).
     * @return Select&\PHPUnit\Framework\MockObject\MockObject
     */
    private function select(bool $withWhere = false): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        if ($withWhere) {
            $select->method('where')->willReturnSelf();
        }
        return $select;
    }

    /**
     * Gateway over a connection that answers the select with $rows.
     *
     * @param Select $select
     * @param list<array<string, mixed>> $rows
     * @return EmailGateway
     */
    private function gateway(Select $select, array $rows): EmailGateway
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->with($select)->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new EmailGateway(
            $this->createMock(OptionalServiceFactory::class),
            $this->createMock(EmailCollectionFactory::class),
            $this->createMock(NewsletterCollectionFactory::class),
            $this->createMock(NewsletterTemplateFactory::class),
            $this->createMock(NewsletterResource::class),
            $this->createMock(EmailResource::class),
            $this->createMock(TransportBuilder::class),
            $resource,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );
    }
}
