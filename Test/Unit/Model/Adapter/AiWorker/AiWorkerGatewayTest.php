<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\AiWorker;

use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Hyva\AiWorker\Model\Config;
use Hyva\AiWorker\Model\JobManager;
use Hyva\AiWorker\Model\Scheduler\SchedulerHealth;
use Hyva\AiWorker\Model\Scheduler\SchedulerLedger;
use Magebit\McpHyvaCommerceTools\Model\Adapter\AiWorker\AiWorkerGateway;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

class AiWorkerGatewayTest extends TestCase
{
    use OptionalServicesStub;

    public function testItemRequestsAndResultsAreOptInAndTruncated(): void
    {
        $jobs = $this->createMock(JobManager::class);
        $jobs->method('getJob')->willReturn(['job_id' => str_repeat('a', 32), 'payload' => ['prompt' => 'secret']]);
        $jobs->method('getItems')->willReturn([[
            'item_id' => 1, 'status' => 'complete', 'payload' => ['prompt' => 'secret'],
            'result' => ['text' => str_repeat('x', 3000)], 'reviewed_result' => 'reviewed',
        ]]);
        $gateway = new AiWorkerGateway(
            $this->optionalServices([
                Config::class => $this->createMock(Config::class),
                JobManager::class => $jobs,
                SchedulerHealth::class => $this->createMock(SchedulerHealth::class),
                SchedulerLedger::class => $this->createMock(SchedulerLedger::class),
            ]),
            $this->createMock(ResourceConnection::class)
        );

        $hidden = $gateway->job(str_repeat('a', 32), [], 10, false);
        $this->assertIsArray($hidden);
        $this->assertArrayNotHasKey('payload', $hidden);
        $this->assertSame([['item_id' => 1, 'status' => 'complete']], $hidden['items']);

        $shown = $gateway->job(str_repeat('a', 32), [], 10, true);
        $this->assertIsArray($shown);
        $this->assertIsArray($shown['items']);
        $item = $shown['items'][0];
        $this->assertIsArray($item);
        $this->assertSame('{"prompt":"secret"}', $item['payload']);
        $this->assertIsString($item['result']);
        $this->assertSame(2000, mb_strlen($item['result']), 'truncated to 2000 characters');
    }
}
