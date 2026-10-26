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
