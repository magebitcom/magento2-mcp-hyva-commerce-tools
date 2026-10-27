<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupSave;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use PHPUnit\Framework\TestCase;

class SoftErrorAuditTest extends TestCase
{
    public function testTheAuditSummaryKeepsACodeNotTheMessage(): void
    {
        $gateway = $this->createMock(LinkedProductsGatewayInterface::class);
        $gateway->method('findIdByName')->willReturn(null);
        $tool = new GroupGet($this->features(), $gateway, $this->guard());
        $result = $tool->execute(['name' => 'secret@example.com']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('No such group', json_encode($result->getContent()) ?: '');
        $this->assertSame(['error' => 'soft_error'], $result->getAuditSummary());
    }

    public function testCallerInputStaysOutOfTheAuditLog(): void
    {
        $gateway = $this->createMock(LinkedProductsGatewayInterface::class);
        $gateway->method('productIdsBySkus')->willReturn([]);
        $gateway->method('check')->willReturn(['issues' => [], 'members' => []]);
        $result = (new GroupSave($this->features(), $gateway, $this->guard()))->execute([
            'name' => 'Leak', 'skus' => ['private-sku-1'], 'confirm' => true,
        ]);

        $this->assertTrue($result->isError());
        $summary = json_encode($result->getAuditSummary()) ?: '';
        $this->assertStringNotContainsString('private-sku-1', $summary, 'caller input stays out of the audit log');
    }

    /**
     * @return FeatureCheckerInterface
     */
    private function features(): FeatureCheckerInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return $features;
    }

    /**
     * Unrestricted scope guard.
     *
     * @return StoreScopeGuard
     */
    private function guard(): StoreScopeGuard
    {
        return $this->createMock(StoreScopeGuard::class);
    }
}
