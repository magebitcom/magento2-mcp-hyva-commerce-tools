<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\CmsTailwind;

use Hyva\CmsTailwindCompiler\Model\DaemonStatusReporter;
use Magebit\McpHyvaCommerceTools\Model\Adapter\CmsTailwind\CmsTailwindGateway;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class CmsTailwindGatewayTest extends TestCase
{
    use OptionalServicesStub;

    public function testStatusCombinesTheReporterPayloadWithTheSettingsAndDropsServerInternals(): void
    {
        $reporter = $this->createMock(DaemonStatusReporter::class);
        $reporter->method('getStatusPayload')->willReturn([
            'host' => 'web-01', 'is_healthy' => false, 'is_cron_manager_enabled' => true,
            'daemon_state' => ['pid' => 7, 'started_at' => '2026-10-27 08:00:00', 'host' => 'web-01'],
            'log_path' => 'var/daemon.log', 'log_size' => 10, 'formatted_log_size' => '10 B',
            'last_log_line' => 'boom', 'restart_pending' => true, 'poison_pill_present' => true,
            'poison_pill_path' => 'var/pill', 'non_writable_files' => ['var/daemon.log'],
        ]);
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['hyva_cms_tailwind/general/daemon_management', 'default', null, 'cron'],
            ['hyva_cms_tailwind/hyva_cms_bridge/compilation_strategy', 'default', null, null],
            ['hyva_cms_tailwind_jit/general/compiler_version', 'default', null, 'v4'],
        ]);
        $status = (new CmsTailwindGateway($this->optionalServices([DaemonStatusReporter::class => $reporter]), $config))
            ->status();

        $this->assertSame([
            'healthy' => false, 'daemon_management' => 'cron', 'compilation_strategy' => 'in-browser',
            'compiler_version' => 'v4', 'daemon' => ['pid' => 7, 'started_at' => '2026-10-27 08:00:00'],
            'restart_pending' => true, 'non_writable_files' => ['var/daemon.log'], 'last_log_line' => 'boom',
        ], $status);
    }

    public function testMissingDaemonStateIsNull(): void
    {
        $reporter = $this->createMock(DaemonStatusReporter::class);
        $reporter->method('getStatusPayload')->willReturn(['is_healthy' => true, 'daemon_state' => null]);
        $config = $this->createMock(ScopeConfigInterface::class);
        $status = (new CmsTailwindGateway($this->optionalServices([DaemonStatusReporter::class => $reporter]), $config))
            ->status();
        $this->assertNull($status['daemon']);
        $this->assertSame('off', $status['daemon_management']);
        $this->assertSame([], $status['non_writable_files']);
    }
}
