<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaForm;

use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form\FormUpdateDraft;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Form writes honour the caller's store view restriction.
 */
class FormScopeTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @var FormGatewayInterface&MockObject
     */
    private FormGatewayInterface $gateway;

    public function testAFormOfAnotherStoreViewIsNotChanged(): void
    {
        $this->gateway($this->form([2]));
        $this->gateway->expects($this->never())->method('update');
        $result = $this->tool([1])->execute(['id' => 3, 'title' => 'Contact us']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testAFormMayNotBeMovedToAllStoreViews(): void
    {
        $this->gateway($this->form([1]));
        $this->gateway->expects($this->never())->method('update');
        $result = $this->tool([1])->execute(['id' => 3, 'store_ids' => [0]]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testAFormOfTheOwnStoreViewIsChanged(): void
    {
        $this->gateway($this->form([1]));
        $this->gateway->expects($this->once())->method('update')->willReturn(['title']);
        $result = $this->tool([1])->execute(['id' => 3, 'title' => 'Contact us']);
        $this->assertFalse($result->isError(), json_encode($result->getContent()) ?: '');
    }

    public function testAnUnrestrictedCallerChangesAllStoreViewsForms(): void
    {
        $this->gateway($this->form([0]));
        $this->gateway->expects($this->once())->method('update')->willReturn(['title']);
        $result = $this->tool(null)->execute(['id' => 3, 'title' => 'Contact us']);
        $this->assertFalse($result->isError(), json_encode($result->getContent()) ?: '');
    }

    /**
     * Gateway returning the form.
     *
     * @param array $form
     * @return void
     * @phpstan-param array<string, mixed> $form
     */
    private function gateway(array $form): void
    {
        $this->gateway = $this->createMock(FormGatewayInterface::class);
        $this->gateway->method('get')->willReturn($form);
    }

    /**
     * Form row assigned to the store views.
     *
     * @param array $storeIds
     * @return array
     * @phpstan-param list<int> $storeIds
     * @phpstan-return array<string, mixed>
     */
    private function form(array $storeIds): array
    {
        return [
            'id' => 3, 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => $storeIds,
            'recipient_to' => 'shop@example.com', 'recipient_cc' => '', 'recipient_bcc' => '',
            'subject_template' => '', 'webhook_url' => '', 'webhook_format' => 'json', 'is_captcha_enabled' => false,
            'preview_url_key' => '', 'has_draft' => true, 'has_published' => true, 'updated_at' => '',
        ];
    }

    /**
     * Tool for a caller restricted to the store views (null = unrestricted).
     *
     * @param array|null $allowedStoreIds
     * @return FormUpdateDraft
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function tool(?array $allowedStoreIds): FormUpdateDraft
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return new FormUpdateDraft(
            $features,
            $this->gateway,
            $this->createMock(ContentService::class),
            new FormRules($this->createMock(ComponentTree::class)),
            $this->storeScope($allowedStoreIds),
            $this->createMock(DraftEditor::class)
        );
    }
}
