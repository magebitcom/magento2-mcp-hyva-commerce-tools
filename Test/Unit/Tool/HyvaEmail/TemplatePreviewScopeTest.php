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
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use PHPUnit\Framework\TestCase;

/**
 * A scope-restricted role previews and renders templates only in its own store views, like send_test.
 */
class TemplatePreviewScopeTest extends TestCase
{
    use RefusalAssertTrait;
    use StoreScopeStub;
    use ToolPayloadTrait;

    public function testAStoreViewOutsideTheRoleIsRefusedAndNothingIsRendered(): void
    {
        foreach ([TemplatePreview::class, NewsletterPreview::class] as $class) {
            $gateway = $this->gateway();
            $gateway->expects($this->never())->method('render');

            $tool = $this->tool($class, $gateway);

            $this->assertRefused(fn () => $tool->execute(['id' => 4, 'store_id' => 2, 'render' => true]), 'scope');
        }
    }

    public function testARestrictedCallerWithoutAStoreViewRendersInItsFirstReadableOne(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->once())->method('render')
            ->with(EmailGatewayInterface::TYPE_EMAIL, 4, 'draft', 1)
            ->willReturn('<p>x</p>');

        $result = $this->tool(TemplatePreview::class, $gateway)->execute(['id' => 4, 'render' => true]);

        $this->assertFalse($result->isError());
    }

    public function testStoreViewZeroIsRefusedForARestrictedCaller(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->never())->method('render');

        $tool = $this->tool(TemplatePreview::class, $gateway);

        $this->assertRefused(fn () => $tool->execute(['id' => 4, 'store_id' => 0, 'render' => true]), 'scope');
    }

    public function testPreviewLinksOfOtherStoreViewsAreLeftOut(): void
    {
        $result = $this->tool(TemplatePreview::class, $this->gateway())->execute(['id' => 4]);

        $this->assertFalse($result->isError());
        $this->assertSame([0, 1], array_column($this->arrayAt($result, 'preview.stores'), 'store_id'));
    }

    public function testEachToolAsksForTheEditorLinkOfItsOwnType(): void
    {
        $types = [TemplatePreview::class => 'email_template', NewsletterPreview::class => 'newsletter_template'];
        foreach ($types as $class => $type) {
            $previews = $this->previews();
            $previews->expects($this->once())->method('getPreview')->with($type, 4);

            $this->tool($class, $this->gateway(), $previews)->execute(['id' => 4]);
        }
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
     * @param PreviewService|null $previews
     * @return TemplatePreview|NewsletterPreview
     */
    private function tool(
        string $class,
        EmailGatewayInterface $gateway,
        ?PreviewService $previews = null
    ): TemplatePreview|NewsletterPreview {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return new $class(
            $features,
            $gateway,
            $this->createMock(ContentService::class),
            $previews ?? $this->previews(),
            $this->storeScope([1])
        );
    }

    /**
     * Preview service with rows for store views 0, 1 and 2.
     *
     * @return PreviewService&MockObject
     */
    private function previews(): PreviewService&MockObject
    {
        $previews = $this->createMock(PreviewService::class);
        $previews->method('getPreview')->willReturn(['admin_editor_url' => 'u', 'error' => null, 'note' => '',
            'stores' => [['store_id' => 0], ['store_id' => 1], ['store_id' => 2]]]);
        return $previews;
    }
}
