<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\EmailTemplates;

use Hyva\EmailTemplates\Model\NewsletterTemplateLoader;
use Hyva\EmailTemplates\Model\TemplateLoader;
use Hyva\EmailTemplates\Model\VersionHistoryCleaner;
use Magebit\McpHyvaCommerceTools\Model\Adapter\EmailTemplates\EmailGateway;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Email\Model\ResourceModel\Template as EmailResource;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory as EmailCollectionFactory;
use Magento\Email\Model\Template as EmailTemplate;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Newsletter\Model\ResourceModel\Template as NewsletterResource;
use Magento\Newsletter\Model\ResourceModel\Template\CollectionFactory as NewsletterCollectionFactory;
use Magento\Newsletter\Model\Template as NewsletterTemplate;
use Magento\Newsletter\Model\TemplateFactory as NewsletterTemplateFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * T-107: the delete tools remove the template through its resource model and then prune Hyvä's version history, the
 * same two steps the model's delete() runs (resource delete, then Hyvä's afterDelete plugin).
 */
class EmailGatewayDeleteTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * Steps run, in order, across the mocks.
     *
     * @var list<string>
     */
    private array $calls = [];

    public function testAnEmailTemplateIsDeletedByItsResourceModelAndItsVersionHistoryPruned(): void
    {
        $template = $this->createMock(EmailTemplate::class);
        $template->method('getId')->willReturn('42');
        $template->expects($this->never())->method('delete');
        $loader = $this->createMock(TemplateLoader::class);
        $loader->method('byId')->with(42)->willReturn($template);
        $emailResource = $this->createMock(EmailResource::class);
        $emailResource->expects($this->once())->method('delete')->with($template)
            ->willReturnCallback(function () use ($emailResource): EmailResource {
                $this->calls[] = 'resource';
                return $emailResource;
            });
        $newsletterResource = $this->createMock(NewsletterResource::class);
        $newsletterResource->expects($this->never())->method('delete');

        $gateway = $this->gateway(
            [TemplateLoader::class => $loader, VersionHistoryCleaner::class => $this->cleaner('email_template', 42)],
            $emailResource,
            $newsletterResource
        );
        $gateway->deleteTemplate(42);

        self::assertSame(['resource', 'cleaner'], $this->calls);
    }

    public function testANewsletterTemplateIsDeletedByItsResourceModelAndItsVersionHistoryPruned(): void
    {
        $template = $this->createMock(NewsletterTemplate::class);
        $template->method('getId')->willReturn('7');
        $template->expects($this->never())->method('delete');
        $loader = $this->createMock(NewsletterTemplateLoader::class);
        $loader->method('byId')->with(7)->willReturn($template);
        $newsletterResource = $this->createMock(NewsletterResource::class);
        $newsletterResource->expects($this->once())->method('delete')->with($template)
            ->willReturnCallback(function () use ($newsletterResource): NewsletterResource {
                $this->calls[] = 'resource';
                return $newsletterResource;
            });
        $emailResource = $this->createMock(EmailResource::class);
        $emailResource->expects($this->never())->method('delete');

        $gateway = $this->gateway(
            [
                NewsletterTemplateLoader::class => $loader,
                VersionHistoryCleaner::class => $this->cleaner('newsletter_template', 7),
            ],
            $emailResource,
            $newsletterResource
        );
        $gateway->deleteNewsletter(7);

        self::assertSame(['resource', 'cleaner'], $this->calls);
    }

    public function testAnUnknownEmailTemplateDeletesNothing(): void
    {
        $loader = $this->createMock(TemplateLoader::class);
        $loader->method('byId')->willReturn(null);
        $emailResource = $this->createMock(EmailResource::class);
        $emailResource->expects($this->never())->method('delete');
        $cleaner = $this->createMock(VersionHistoryCleaner::class);
        $cleaner->expects($this->never())->method('deleteForEntity');

        $gateway = $this->gateway(
            [TemplateLoader::class => $loader, VersionHistoryCleaner::class => $cleaner],
            $emailResource,
            $this->createMock(NewsletterResource::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Email template #5 does not exist.');
        $gateway->deleteTemplate(5);
    }

    public function testAnUnknownNewsletterTemplateDeletesNothing(): void
    {
        $loader = $this->createMock(NewsletterTemplateLoader::class);
        $loader->method('byId')->willReturn(null);
        $newsletterResource = $this->createMock(NewsletterResource::class);
        $newsletterResource->expects($this->never())->method('delete');
        $cleaner = $this->createMock(VersionHistoryCleaner::class);
        $cleaner->expects($this->never())->method('deleteForEntity');

        $gateway = $this->gateway(
            [NewsletterTemplateLoader::class => $loader, VersionHistoryCleaner::class => $cleaner],
            $this->createMock(EmailResource::class),
            $newsletterResource
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Newsletter template #5 does not exist.');
        $gateway->deleteNewsletter(5);
    }

    /**
     * Version-history cleaner that expects one call for the entity and records it.
     *
     * @param string $entityType
     * @param int $id
     * @return VersionHistoryCleaner
     */
    private function cleaner(string $entityType, int $id): VersionHistoryCleaner
    {
        $cleaner = $this->createMock(VersionHistoryCleaner::class);
        $cleaner->expects($this->once())->method('deleteForEntity')->with($entityType, $id)
            ->willReturnCallback(function (): void {
                $this->calls[] = 'cleaner';
            });
        return $cleaner;
    }

    /**
     * Gateway over the given optional services and resource models.
     *
     * @param array<class-string, object> $services
     * @param EmailResource $emailResource
     * @param NewsletterResource $newsletterResource
     * @return EmailGateway
     */
    private function gateway(
        array $services,
        EmailResource $emailResource,
        NewsletterResource $newsletterResource
    ): EmailGateway {
        return new EmailGateway(
            $this->optionalServices($services),
            $this->createMock(EmailCollectionFactory::class),
            $this->createMock(NewsletterCollectionFactory::class),
            $this->createMock(NewsletterTemplateFactory::class),
            $newsletterResource,
            $emailResource,
            $this->createMock(TransportBuilder::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(SearchCriteriaBuilder::class)
        );
    }
}
