<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Widget;

use Hyva\AdminDashboardApi\Api\V1\WidgetContextInterface;
use Magebit\McpHyvaCommerceTools\Model\Audit\McpActivityReader;
use Magebit\McpHyvaCommerceTools\Model\Widget\McpActivity;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class McpActivityAclTest extends TestCase
{
    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function grants(): array
    {
        return [
            'widget and audit' => [true, true, true],
            'widget only' => [true, false, false],
            'audit only' => [false, true, false],
        ];
    }

    /**
     * @dataProvider grants
     * @param bool $widget
     * @param bool $audit
     * @param bool $expected
     * @return void
     */
    public function testTheWidgetNeedsTheCoreAuditPermission(bool $widget, bool $audit, bool $expected): void
    {
        $ctx = $this->createMock(WidgetContextInterface::class);
        $ctx->method('isAllowed')->willReturn($widget);
        $widgetModel = new McpActivity($this->reader($audit), $this->createMock(UrlInterface::class));

        $this->assertSame($expected, $widgetModel->isAllowed($ctx, null));
    }

    public function testTheReaderReturnsNothingWithoutTheAuditPermission(): void
    {
        $connection = $this->createMock(ResourceConnection::class);
        $connection->expects($this->never())->method('getConnection');
        $reader = new McpActivityReader($connection, $this->authorization(false));

        $this->assertSame([], $reader->getRows(['tool_prefix' => 'hyva_media.'], 5));
        $this->assertSame(['calls' => 0, 'errors' => 0, 'writes' => 0, 'admins' => 0], $reader->getTotals('2026-01-01'));
    }

    /**
     * @param bool $audit
     * @return McpActivityReader
     */
    private function reader(bool $audit): McpActivityReader
    {
        return new McpActivityReader($this->createMock(ResourceConnection::class), $this->authorization($audit));
    }

    /**
     * @param bool $audit
     * @return AuthorizationInterface
     */
    private function authorization(bool $audit): AuthorizationInterface
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => $resource === McpActivityReader::AUDIT_RESOURCE ? $audit : true
        );
        return $authorization;
    }
}
