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
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterPreview;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplatePreview;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A scope-restricted role previews and renders templates only in its own store views, like send_test.
 */
class TemplatePreviewScopeTest extends TestCase
{
    use StoreScopeStub;
    use ToolPayloadTrait;

    public function testAStoreViewOutsideTheRoleIsRefusedAndNothingIsRendered(): void
    {
        foreach ([TemplatePreview::class, NewsletterPreview::class] as $class) {
            $gateway = $this->gateway();
            $gateway->expects($this->never())->method('render');

            $result = $this->tool($class, $gateway)->execute(['id' => 4, 'store_id' => 2, 'render' => true]);

            $this->assertTrue($result->isError(), $class);
            $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '', $class);
        }
    }

    public function testPreviewLinksOfOtherStoreViewsAreLeftOut(): void
    {
        $result = $this->tool(TemplatePreview::class, $this->gateway())->execute(['id' => 4]);

        $this->assertFalse($result->isError());
        $this->assertSame([0, 1], array_column($this->arrayAt($result, 'preview.stores'), 'store_id'));
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
     * Preview tool for a caller restricted to store view 1.
     *
     * @param class-string<TemplatePreview|NewsletterPreview> $class
     * @param EmailGatewayInterface $gateway
     * @return TemplatePreview|NewsletterPreview
     */
    private function tool(string $class, EmailGatewayInterface $gateway): TemplatePreview|NewsletterPreview
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $previews = $this->createMock(PreviewService::class);
        $previews->method('getPreview')->willReturn(['admin_editor_url' => 'u', 'error' => null, 'note' => '',
            'stores' => [['store_id' => 0], ['store_id' => 1], ['store_id' => 2]]]);
        return new $class($features, $gateway, $this->createMock(ContentService::class), $previews,
            $this->storeScope([1]));
    }
}
