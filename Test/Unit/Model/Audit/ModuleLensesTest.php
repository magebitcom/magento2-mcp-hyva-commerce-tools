<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Audit;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\StoreCreditGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Audit\ModuleLenses;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class ModuleLensesTest extends TestCase
{
    public function testUnavailableModulesAndDeniedReadsGiveNullLenses(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturnCallback(
            static fn (string $feature): bool => in_array($feature, [
                FeatureCheckerInterface::EMAIL_TEMPLATES,
                FeatureCheckerInterface::LINKED_PRODUCTS,
                FeatureCheckerInterface::MEDIA_GALLERY,
            ], true)
        );
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(false);
        $contentAcl = $this->createMock(ContentAcl::class);
        $contentAcl->method('allowedTypes')->willReturn([]);
        $emails = $this->createMock(EmailGatewayInterface::class);
        $emails->expects($this->never())->method('getList');
        $forms = $this->createMock(FormGatewayInterface::class);
        $forms->expects($this->never())->method('getList');
        $auditor = $this->createMock(LiteralUrlAuditor::class);
        $auditor->expects($this->never())->method('audit');
        $linked = $this->createMock(LinkedProductsGatewayInterface::class);
        $linked->method('getList')->willReturn(['total' => 3, 'items' => []]);

        $lenses = (new ModuleLenses(
            $features,
            $authorization,
            $contentAcl,
            $forms,
            $this->createMock(FormRules::class),
            $emails,
            $auditor,
            $this->createMock(StoreCreditGatewayInterface::class),
            $linked
        ))->collect();

        $this->assertNull($lenses['forms'], 'Form Builder unavailable');
        $this->assertNull($lenses['emails'], 'email templates installed, but no email_template_view');
        $this->assertNull($lenses['media_asset_ids'], 'no content type the caller may view');
        $this->assertNull($lenses['store_credit']);
        $this->assertSame(3, $lenses['linked_products']['groups'] ?? null);
    }
}
