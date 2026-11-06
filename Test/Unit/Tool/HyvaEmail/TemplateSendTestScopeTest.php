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
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\MailException;
use PHPUnit\Framework\MockObject\MockObject;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use PHPUnit\Framework\TestCase;

/**
 * A scope-restricted role sends test emails rendered only for its own store views.
 */
class TemplateSendTestScopeTest extends TestCase
{
    use RefusalAssertTrait;
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

        $tool = $this->tool($gateway);

        $this->assertRefused(
            fn () => $tool->execute(['id' => 4, 'recipient' => 'a@b.co', 'store_id' => 0]),
            'scope'
        );
    }

    public function testTransportFailureIsSoftError(): void
    {
        $gateway = $this->gateway();
        $gateway->method('sendTest')
            ->willThrowException(new MailException(__('Unable to send mail at this time.')));

        $result = $this->tool($gateway)->execute(['id' => 4, 'recipient' => 'a@b.co', 'store_id' => 1]);

        $this->assertTrue($result->isError());
        $text = $result->getContent()[0]['text'] ?? '';
        $reply = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($reply);
        $this->assertFalse($reply['sent']);
        $this->assertSame('transport_error', $reply['status']);
        $this->assertSame(4, $reply['id']);
        $this->assertStringContainsString('Unable to send mail at this time.', (string) $reply['message']);
        $this->assertSame($reply['message'], $reply['error']);
    }

    public function testOtherFailuresStillPropagate(): void
    {
        $gateway = $this->gateway();
        $gateway->method('sendTest')
            ->willThrowException(new LocalizedException(__('Email template #4 has no published Hyvä content.')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no published Hyvä content');

        $this->tool($gateway)->execute(['id' => 4, 'recipient' => 'a@b.co', 'store_id' => 1]);
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
