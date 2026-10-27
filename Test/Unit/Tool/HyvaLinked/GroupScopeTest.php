<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaLinked;

use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupSave;
use PHPUnit\Framework\TestCase;

/**
 * Linked product groups are global: a scope-restricted role may not change them.
 */
class GroupScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testARestrictedRoleMayNotSaveAGroup(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gateway = $this->createMock(LinkedProductsGatewayInterface::class);
        $gateway->expects($this->never())->method('save');

        $result = (new GroupSave($features, $gateway, $this->storeScope([1])))->execute([
            'name' => 'Colours', 'attributes' => ['color'], 'skus' => ['A'], 'confirm' => true,
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }
}
