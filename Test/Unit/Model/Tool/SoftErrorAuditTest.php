<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageDelete;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterPublish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupSave;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use PHPUnit\Framework\TestCase;

class SoftErrorAuditTest extends TestCase
{
    use StoreScopeStub;

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
     * T-90: a store scope refusal is audited with its reason, like the in-use refusal; the reply is the same soft 403.
     */
    public function testAScopeRefusalIsAuditedAsOutOfScope(): void
    {
        $result = (new NewsletterPublish(
            $this->features(),
            $this->createMock(EmailGatewayInterface::class),
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope([1])
        ))->execute(['id' => 4, 'confirm' => true]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
        $this->assertSame(['error' => 'out_of_scope'], $result->getAuditSummary());
    }

    public function testAPermissionRefusalKeepsTheStatusCode(): void
    {
        $caller = $this->createMock(CallerContext::class);
        $caller->method('isAllowed')->willReturn(false);
        $result = (new PageDelete(
            $this->createMock(ContentLocator::class),
            new ContentAcl($this->createMock(ContentTypeAuthorization::class), $caller),
            $caller,
            $this->createMock(DeletionUsage::class),
            $this->createMock(EntityWriter::class)
        ))->execute(['id' => 5, 'confirm' => true]);

        $this->assertTrue($result->isError());
        $this->assertSame(['error' => 'status_403'], $result->getAuditSummary());
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
