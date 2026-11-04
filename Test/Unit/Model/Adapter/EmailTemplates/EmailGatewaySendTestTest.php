<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\EmailTemplates;

use Hyva\EmailTemplates\Api\Data\EmailTemplateInterface;
use Hyva\EmailTemplates\Api\EmailTemplateRepositoryInterface;
use Hyva\EmailTemplates\Model\OrderPreviewData;
use Hyva\EmailTemplates\Model\Preview\FixtureDefaults;
use Hyva\EmailTemplates\Model\Preview\PreviewFixtureProvider;
use Hyva\EmailTemplates\Model\Preview\TestSendContext;
use Magebit\McpHyvaCommerceTools\Model\Adapter\EmailTemplates\EmailGateway;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Email\Model\ResourceModel\Template as EmailResource;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as EmailCollectionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Newsletter\Model\ResourceModel\Template as NewsletterResource;
use Magento\Newsletter\Model\ResourceModel\Template\CollectionFactory as NewsletterCollectionFactory;
use Magento\Newsletter\Model\TemplateFactory as NewsletterTemplateFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-154: hyva_email.template.send_test renders and sends inside Hyvä's TestSendContext (Email 1.0.1+), as the admin
 * "Send test" does, so order components show the preview fixture instead of an empty production order.
 */
class EmailGatewaySendTestTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * Whether the test-send context was active at each transport step.
     *
     * @var array<string, bool>
     */
    private array $active = [];

    /**
     * Template variables the transport received.
     *
     * @var array<string, mixed>
     */
    private array $vars = [];

    protected function setUp(): void
    {
        if (!class_exists(TestSendContext::class)) {
            $this->markTestSkipped('Email & Newsletter Templates 1.0.0 has no TestSendContext.');
        }
    }

    public function testTheMessageIsRenderedAndSentInsideTheTestSendContext(): void
    {
        $context = new TestSendContext();

        $this->gateway($context)->sendTest(5, 'qa@example.com', 1);

        self::assertSame(['getTransport' => true, 'sendMessage' => true], $this->active);
        self::assertFalse($context->isActive(), 'The context is left after the send.');
    }

    public function testTheSampleOrderFallsBackToHyvasFixtureDefaults(): void
    {
        $this->gateway(new TestSendContext())->sendTest(5, 'qa@example.com', null);

        $order = $this->vars['order'] ?? null;
        self::assertInstanceOf(DataObject::class, $order);
        self::assertSame(FixtureDefaults::GRAND_TOTAL, $order->getData('grand_total'));
        self::assertSame(FixtureDefaults::INCREMENT_ID, $order->getData('increment_id'));
        self::assertSame(FixtureDefaults::paymentHtml(), $this->vars['payment_html'] ?? null);
    }

    /**
     * Gateway over a published Hyvä email template #5, with no real order in the store.
     *
     * @param TestSendContext $context
     * @return EmailGateway
     */
    private function gateway(TestSendContext $context): EmailGateway
    {
        $record = $this->createMock(EmailTemplateInterface::class);
        $record->method('isLiveviewEnabled')->willReturn(true);
        $record->method('getPublishedContent')->willReturn('[{"type":"text"}]');
        $repository = $this->createMock(EmailTemplateRepositoryInterface::class);
        $repository->method('getByEmailTemplateId')->with(5)->willReturn($record);
        $fixtures = $this->createMock(PreviewFixtureProvider::class);
        $fixtures->method('getCustomerData')->willReturn(['first_name' => 'Jane', 'last_name' => 'Doe',
            'email' => 'jane@example.com']);
        $orders = $this->createMock(OrderPreviewData::class);
        $orders->method('getOrderContext')->willReturn(null);

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('sendMessage')->willReturnCallback(
            function () use ($context): void {
                $this->active['sendMessage'] = $context->isActive();
            }
        );
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFrom', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('setTemplateVars')->willReturnCallback(
            function (array $vars) use ($builder): TransportBuilder {
                $this->vars = $vars;
                return $builder;
            }
        );
        $builder->method('getTransport')->willReturnCallback(
            function () use ($context, $transport): TransportInterface {
                $this->active['getTransport'] = $context->isActive();
                return $transport;
            }
        );

        return new EmailGateway(
            $this->optionalServices([
                EmailTemplateRepositoryInterface::class => $repository,
                PreviewFixtureProvider::class => $fixtures,
                OrderPreviewData::class => $orders,
                TestSendContext::class => $context,
            ]),
            $this->createMock(EmailCollectionFactory::class),
            $this->createMock(NewsletterCollectionFactory::class),
            $this->createMock(NewsletterTemplateFactory::class),
            $this->createMock(NewsletterResource::class),
            $this->createMock(EmailResource::class),
            $builder,
            $this->createMock(ResourceConnection::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );
    }
}
