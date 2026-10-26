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
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class ModuleLensesTest extends TestCase
{
    use ContentScopeStub;

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
            $linked,
            $this->contentScope(null)
        ))->collect();

        $this->assertNull($lenses['forms'], 'Form Builder unavailable');
        $this->assertNull($lenses['emails'], 'email templates installed, but no email_template_view');
        $this->assertNull($lenses['media_asset_ids'], 'no content type the caller may view');
        $this->assertNull($lenses['store_credit']);
        $this->assertSame(3, $lenses['linked_products']['groups'] ?? null);
    }

    /**
     * A restricted caller's forms lens covers its own forms on every page (finding: unscoped image/audit lists).
     *
     * @return void
     */
    public function testARestrictedCallersFormLensCountsOnlyItsForms(): void
    {
        $forms = $this->createMock(FormGatewayInterface::class);
        $forms->expects($this->exactly(2))->method('getList')->willReturnCallback(
            fn (?string $search, ?int $storeId, int $page, int $size): array => [
                'total' => 201,
                'items' => $page === 1
                    ? [$this->form('own', [1], false), $this->form('foreign', [2], true)]
                    : [$this->form('shared', [0], true)],
            ]
        );

        $lens = $this->formLenses($forms, [1])->collect()['forms'];

        $this->assertSame(2, $lens['total'] ?? null);
        $this->assertSame(1, $lens['inactive'] ?? null);
        $this->assertSame(1, $lens['forms_hidden_by_permissions'] ?? null);
    }

    /**
     * An unrestricted caller's forms lens reads one page and has no hidden counter.
     *
     * @return void
     */
    public function testAnUnrestrictedCallersFormLensIsUnchanged(): void
    {
        $forms = $this->createMock(FormGatewayInterface::class);
        $forms->expects($this->once())->method('getList')->willReturn([
            'total' => 201,
            'items' => [$this->form('own', [1], false), $this->form('foreign', [2], true)],
        ]);

        $lens = $this->formLenses($forms, null)->collect()['forms'];

        $this->assertSame(201, $lens['total'] ?? null);
        $this->assertIsArray($lens);
        $this->assertArrayNotHasKey('forms_hidden_by_permissions', $lens);
    }

    /**
     * Lenses with only Form Builder available.
     *
     * @param FormGatewayInterface $forms
     * @param array|null $allowedStoreIds
     * @return ModuleLenses
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function formLenses(FormGatewayInterface $forms, ?array $allowedStoreIds): ModuleLenses
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturnCallback(
            static fn (string $feature): bool => $feature === FeatureCheckerInterface::FORM_BUILDER
        );
        $contentAcl = $this->createMock(ContentAcl::class);
        $contentAcl->method('allowedTypes')->willReturn([]);
        $formRules = $this->createMock(FormRules::class);
        $formRules->method('deliveryIssues')->willReturn([]);
        return new ModuleLenses(
            $features,
            $this->createMock(AuthorizationInterface::class),
            $contentAcl,
            $forms,
            $formRules,
            $this->createMock(EmailGatewayInterface::class),
            $this->createMock(LiteralUrlAuditor::class),
            $this->createMock(StoreCreditGatewayInterface::class),
            $this->createMock(LinkedProductsGatewayInterface::class),
            $this->contentScope($allowedStoreIds)
        );
    }

    /**
     * Form row.
     *
     * @param string $identifier
     * @param array $storeIds
     * @param bool $active
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return array<string, mixed>
     */
    private function form(string $identifier, array $storeIds, bool $active): array
    {
        return ['id' => 1, 'identifier' => $identifier, 'title' => $identifier, 'is_active' => $active,
            'store_ids' => $storeIds];
    }
}
