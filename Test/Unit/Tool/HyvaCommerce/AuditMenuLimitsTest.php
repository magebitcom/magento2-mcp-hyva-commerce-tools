<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCommerce;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit\AuditMenu;
use Magebit\Mcp\Api\ToolResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * hyva_commerce.audit.menu hands its max_kb and limit arguments to the media audit, like audit.media.
 */
class AuditMenuLimitsTest extends TestCase
{
    use ContentScopeStub;

    public function testMaxKbAndLimitReachTheMediaAudit(): void
    {
        $this->assertSame(['menu', 120, 7], $this->auditCall(['max_kb' => 120, 'limit' => 7]));
    }

    public function testTheDefaultsAreKept(): void
    {
        $this->assertSame(['menu', 500, 20], $this->auditCall([]));
    }

    /**
     * A media audit with no image problems keeps media.counts a JSON object in the reply (T-115).
     */
    public function testEmptyMediaCountsEncodeAsObject(): void
    {
        $text = $this->toolResult([])->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $this->assertStringContainsString('"counts": {}', $text);
    }

    /**
     * Arguments MediaAuditor::audit() receives from the tool.
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<mixed>
     */
    private function auditCall(array $arguments): array
    {
        $received = [];
        $this->toolResult($arguments, $received);
        return $received;
    }

    /**
     * Tool result over no menus and a media audit with no image problems.
     *
     * @param array $arguments
     * @param array $received Set to the arguments MediaAuditor::audit() receives.
     * @return ToolResultInterface
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-param list<mixed> $received
     */
    private function toolResult(array $arguments, array &$received = []): ToolResultInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gateway = $this->createMock(MenuGatewayInterface::class);
        $gateway->method('getList')->willReturn([]);
        $auditor = $this->createMock(MediaAuditor::class);
        $auditor->method('audit')->willReturnCallback(
            static function (string $scope, int $maxKb, int $limit) use (&$received): array {
                $received = [$scope, $maxKb, $limit];
                return ['scope' => $scope, 'images_checked' => 0, 'counts' => new \stdClass(),
                    'config_issues' => [], 'images' => []];
            }
        );
        return (new AuditMenu(
            $features,
            $gateway,
            $this->createMock(MenuService::class),
            $auditor,
            $this->contentScope(null)
        ))->execute($arguments);
    }
}
