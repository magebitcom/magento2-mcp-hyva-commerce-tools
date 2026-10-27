<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Migration\MigrationScan;
use Magebit\Mcp\Api\ToolResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * hyva_cms.migration.scan lists only pages and blocks of the caller's store views.
 */
class MigrationScanScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testTheScanSkipsContentOutsideTheRolesStoreViews(): void
    {
        $include = $this->includeCallback([1]);
        $this->assertTrue($include([1]));
        $this->assertTrue($include([0]));
        $this->assertFalse($include([2]));
    }

    public function testAnUnrestrictedCallerScansEverything(): void
    {
        $this->assertTrue($this->includeCallback(null)([2]));
    }

    public function testTheItemsAreLimitedButTheSummaryCountsEveryRow(): void
    {
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['kind' => 'wysiwyg', 'converted_draft_waiting' => false, 'id' => $i];
        }
        $service = $this->createMock(MigrationService::class);
        $service->method('scan')->willReturn($rows);
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        $tool = new MigrationScan($service, $acl, $this->storeScope(null));

        $default = $this->payload($tool->execute([]));
        $summary = $default['summary'] ?? null;
        $this->assertIsArray($summary);
        $this->assertSame(60, $summary['total'] ?? null);
        $this->assertCount(50, $this->payloadList($default, 'items'));
        $this->assertCount(10, $this->payloadList($this->payload($tool->execute(['limit' => 10])), 'items'));
    }

    /**
     * @param array $payload
     * @param string $key
     * @return array
     * @phpstan-param array<string, mixed> $payload
     * @phpstan-return array<mixed>
     */
    private function payloadList(array $payload, string $key): array
    {
        $this->assertIsArray($payload[$key] ?? null);
        return $payload[$key];
    }

    /**
     * @param ToolResultInterface $result
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        return $payload;
    }

    /**
     * The store view filter the tool hands to MigrationService::scan().
     *
     * @param array|null $allowedStoreIds
     * @return callable
     * @phpstan-param list<int>|null $allowedStoreIds
     * @phpstan-return callable(list<int>): bool
     */
    private function includeCallback(?array $allowedStoreIds): callable
    {
        $include = null;
        $service = $this->createMock(MigrationService::class);
        $service->expects($this->once())->method('scan')->willReturnCallback(
            static function (array $types, ?callable $includeStores) use (&$include): array {
                $include = $includeStores;
                return [];
            }
        );
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('isAllowed')->willReturn(true);
        (new MigrationScan($service, $acl, $this->storeScope($allowedStoreIds)))->execute([]);
        $this->assertIsCallable($include);
        return static fn (array $storeIds): bool => (bool) $include($storeIds);
    }
}
