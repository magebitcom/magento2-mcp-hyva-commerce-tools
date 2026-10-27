<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaAi;

use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AiWorkerGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Job\JobGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Job\JobList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Worker\WorkerStatus;
use Magento\Framework\AuthorizationInterface;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OwnJobsTest extends TestCase
{
    /**
     * @var AiWorkerGatewayInterface&MockObject
     */
    private AiWorkerGatewayInterface $gateway;

    /**
     * @var bool Caller holds Hyva_AiWorker::jobs
     */
    private bool $allJobs = false;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(AiWorkerGatewayInterface::class);
    }

    public function testListIsLimitedToOwnJobsWithoutTheJobsResource(): void
    {
        $this->gateway->expects($this->once())->method('jobs')
            ->with(['status' => ['failed'], 'admin_user_id' => 6], 1, 50)
            ->willReturn(['total' => 0, 'items' => []]);
        $result = $this->tool(JobList::class)->execute(['status' => ['failed'], 'admin_user_id' => 1]);
        $this->assertStringContainsString('own_jobs_only\\": true', json_encode($result->getContent()) ?: '');
    }

    public function testListKeepsTheRequestedAdminWithTheJobsResource(): void
    {
        $this->allJobs = true;
        $this->gateway->expects($this->once())->method('jobs')
            ->with(['admin_user_id' => 1], 1, 50)
            ->willReturn(['total' => 0, 'items' => []]);
        $this->tool(JobList::class)->execute(['admin_user_id' => 1]);
    }

    public function testAnotherAdminsJobIsRefused(): void
    {
        $this->gateway->method('job')->willReturn(['job_id' => str_repeat('a', 32), 'admin_user_id' => 1]);
        $result = $this->tool(JobGet::class)->execute(['job_id' => str_repeat('a', 32)]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testAJobWithoutOwnerIsNotOwn(): void
    {
        $this->gateway->method('job')->willReturn(['job_id' => str_repeat('b', 32), 'admin_user_id' => null]);
        $result = $this->tool(JobGet::class)->execute(['job_id' => str_repeat('b', 32)]);
        $this->assertTrue($result->isError(), 'CLI and scheduler jobs need Hyva_AiWorker::jobs');
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testWorkerStatusSpellsOutWhatStopsJobs(): void
    {
        $this->gateway->method('status')->willReturn(['enabled' => true, 'configured' => false,
            'scheduler' => ['healthy' => true, 'cron_stale' => true, 'cron_last_run' => null, 'problems' => []]]);
        $text = json_encode($this->tool(WorkerStatus::class)->execute([])->getContent()) ?: '';
        $this->assertStringContainsString('ready\\": false', $text);
        $this->assertStringContainsString('Not configured', $text);
        $this->assertStringContainsString('Cron is not running', $text);
    }

    public function testWorkerStatusNamesTheDisabledPhpWorker(): void
    {
        $this->gateway->method('status')->willReturn(['enabled' => true, 'configured' => false,
            'uses_php_worker' => true, 'php_worker_enabled' => false,
            'scheduler' => ['cron_stale' => true, 'active_jobs' => 2, 'problems' => ['Bulk AI generation is paused']]]);
        $text = json_encode($this->tool(WorkerStatus::class)->execute([])->getContent()) ?: '';
        $this->assertStringContainsString('PHP worker is disabled', $text);
        $this->assertStringNotContainsString('Cron is not running', $text, 'Hyvä already reports it');
        $this->assertStringContainsString('Bulk AI generation is paused', $text);
    }

    /**
     * @param class-string<JobList|JobGet|WorkerStatus> $class
     * @return JobList|JobGet|WorkerStatus
     */
    private function tool(string $class): JobList|JobGet|WorkerStatus
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('getStatus')->willReturn(['enabled' => true]);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(fn (): bool => $this->allJobs);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $context = new AuthenticatedContextProvider();
        $context->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $caller = new CallerContext($context, $this->createMock(AclChecker::class));
        return new $class($features, $this->gateway, $authorization, $caller);
    }
}
