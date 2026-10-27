<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\CmsTailwindGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Tailwind\TailwindStatus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TailwindStatusTest extends TestCase
{
    /**
     * @var CmsTailwindGatewayInterface&MockObject
     */
    private CmsTailwindGatewayInterface $gateway;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(CmsTailwindGatewayInterface::class);
    }

    public function testHealthyDaemonHasNoHint(): void
    {
        $this->gateway->method('status')->willReturn($this->baseStatus([
            'healthy' => true,
            'daemon_management' => 'cron',
            'compilation_strategy' => 'daemon',
            'daemon' => ['pid' => 4242, 'started_at' => '2026-10-27 08:00:00'],
        ]));
        $data = $this->payload();
        $this->assertTrue($data['healthy']);
        $this->assertSame('cron', $data['daemon_management']);
        $this->assertSame('daemon', $data['compilation_strategy']);
        $this->assertSame('v3', $data['compiler_version']);
        $this->assertSame(['pid' => 4242, 'started_at' => '2026-10-27 08:00:00'], $data['daemon']);
        $this->assertFalse($data['restart_pending']);
        $this->assertSame([], $data['non_writable_files']);
        $this->assertNull($data['hint']);
    }

    public function testUnhealthyDaemonStrategyExplainsUncompiledClasses(): void
    {
        $this->gateway->method('status')->willReturn($this->baseStatus([
            'healthy' => false,
            'compilation_strategy' => 'daemon',
            'last_log_line' => 'Compiler crashed',
            'non_writable_files' => ['var/hyva-cms-tailwind-daemon.log'],
        ]));
        $data = $this->payload();
        $this->assertFalse($data['healthy']);
        $this->assertNull($data['daemon']);
        $this->assertSame('Compiler crashed', $data['last_log_line']);
        $this->assertSame(['var/hyva-cms-tailwind-daemon.log'], $data['non_writable_files']);
        $this->assertIsString($data['hint']);
        $this->assertStringContainsString('not compiled until the daemon runs', $data['hint']);
    }

    public function testUnhealthyWithInBrowserStrategyHasNoHint(): void
    {
        $this->gateway->method('status')->willReturn($this->baseStatus(['healthy' => false]));
        $this->assertNull($this->payload()['hint']);
    }

    public function testDaemonCompilerVersionForcesTheDaemonStrategy(): void
    {
        $this->gateway->method('status')->willReturn(
            $this->baseStatus(['healthy' => false, 'compiler_version' => 'daemon'])
        );
        $this->assertIsString($this->payload()['hint']);
    }

    public function testServerInternalsAreNotReturned(): void
    {
        $this->gateway->method('status')->willReturn($this->baseStatus([
            'host' => 'web-01.internal',
            'log_path' => 'var/hyva-cms-tailwind-daemon.log',
            'poison_pill_path' => 'var/hyva-cms-tailwind-restart',
        ]));
        $text = $this->text($this->tool(true)->execute([]));
        foreach (['web-01.internal', 'log_path', 'poison_pill_path', 'var/hyva-cms-tailwind', '"host"'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
    }

    public function testAbsentFeatureReturnsUnavailable(): void
    {
        $this->gateway->expects($this->never())->method('status');
        $result = $this->tool(false)->execute([]);
        $data = json_decode($this->text($result), true);
        $this->assertIsArray($data);
        $this->assertFalse($data['available']);
        $this->assertSame(FeatureCheckerInterface::CMS_TAILWIND, $data['feature']);
    }

    public function testToolIsReadOnlyAndUsesTheDashboardWidgetResource(): void
    {
        $tool = $this->tool(true);
        $this->assertSame('hyva_cms.tailwind.status', $tool->getName());
        $this->assertSame('Magento_Config::config_system', $tool->getUnderlyingAclResource());
        $this->assertFalse($tool->getConfirmationRequired());
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function baseStatus(array $override): array
    {
        return $override + [
            'healthy' => true, 'daemon_management' => 'off', 'compilation_strategy' => 'in-browser',
            'compiler_version' => 'v3', 'daemon' => null, 'restart_pending' => false,
            'non_writable_files' => [], 'last_log_line' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $data = json_decode($this->text($this->tool(true)->execute([])), true);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * @param ToolResultInterface $result
     * @return string
     */
    private function text(ToolResultInterface $result): string
    {
        $content = $result->getContent();
        $first = $content[0] ?? [];
        return Cast::string(is_array($first) ? ($first['text'] ?? '') : '');
    }

    private function tool(bool $available): TailwindStatus
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($available);
        $features->method('getUnavailableResponse')->willReturn([
            'available' => false, 'feature' => FeatureCheckerInterface::CMS_TAILWIND,
            'reason' => 'not installed', 'hint' => 'Install it',
        ]);
        return new TailwindStatus($features, $this->gateway);
    }
}
