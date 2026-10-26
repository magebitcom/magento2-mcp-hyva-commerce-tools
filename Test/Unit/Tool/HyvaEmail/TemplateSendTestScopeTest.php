<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaEmail;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplateSendTest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A scope-restricted role sends test emails rendered only for its own store views.
 */
class TemplateSendTestScopeTest extends TestCase
{
    use StoreScopeStub;

    public function testWithoutAStoreViewTheFirstReadableOneIsUsed(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->once())->method('sendTest')->with(4, 'a@b.co', 1);

        $result = $this->tool($gateway)->execute(['id' => 4, 'recipient' => 'a@b.co']);

        $this->assertFalse($result->isError());
    }

    public function testStoreViewZeroIsRefusedAndNothingIsSent(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->never())->method('sendTest');

        $result = $this->tool($gateway)->execute(['id' => 4, 'recipient' => 'a@b.co', 'store_id' => 0]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    /**
     * Gateway knowing template 4.
     *
     * @return EmailGatewayInterface&MockObject
     */
    private function gateway(): EmailGatewayInterface&MockObject
    {
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->method('get')->willReturn(['id' => 4, 'code' => 'welcome']);
        return $gateway;
    }

    /**
     * Send-test tool for a caller restricted to store view 1.
     *
     * @param EmailGatewayInterface $gateway
     * @return TemplateSendTest
     */
    private function tool(EmailGatewayInterface $gateway): TemplateSendTest
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return new TemplateSendTest(
            $features,
            $gateway,
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope([1])
        );
    }
}
