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
use Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form\FormUpdateDraft;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FormUpdateDraftTest extends TestCase
{
    /**
     * @var FormGatewayInterface&MockObject
     */
    private FormGatewayInterface $gateway;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(FormGatewayInterface::class);
        $this->gateway->method('get')->willReturn([
            'id' => 3, 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => [0],
            'recipient_to' => 'shop@example.com', 'recipient_cc' => 'not-an-address', 'recipient_bcc' => '',
            'subject_template' => '', 'webhook_url' => '', 'webhook_format' => 'json', 'is_captcha_enabled' => false,
            'preview_url_key' => '', 'has_draft' => true, 'has_published' => true, 'updated_at' => '',
        ]);
    }

    public function testAFormWithAnExistingDeliveryErrorStaysEditable(): void
    {
        $this->gateway->expects($this->once())->method('update')
            ->with(3, ['title' => 'Contact us'])
            ->willReturn(['title']);
        $result = $this->tool()->execute(['id' => 3, 'title' => 'Contact us']);
        $this->assertFalse($result->isError(), json_encode($result->getContent()) ?: '');
    }

    public function testANewBadRecipientIsRefusedBeforeSaving(): void
    {
        $this->gateway->expects($this->never())->method('update');
        $result = $this->tool()->execute(['id' => 3, 'recipient_bcc' => 'also-bad']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('invalid_recipient', json_encode($result->getContent()) ?: '');
    }

    public function testSwappingABadWebhookForAnotherBadOneIsRefused(): void
    {
        $this->gateway = $this->createMock(FormGatewayInterface::class);
        $this->gateway->method('get')->willReturn([
            'id' => 3, 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => [0],
            'recipient_to' => 'shop@example.com', 'recipient_cc' => '', 'recipient_bcc' => '',
            'subject_template' => '', 'webhook_url' => 'http://10.0.0.1/hook', 'webhook_format' => 'json',
            'is_captcha_enabled' => false, 'preview_url_key' => '', 'has_draft' => true, 'has_published' => true,
            'updated_at' => '',
        ]);
        $this->gateway->expects($this->never())->method('update');
        $result = $this->tool()->execute(['id' => 3, 'webhook_url' => 'http://192.168.0.5/hook']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('webhook_not_dispatchable', json_encode($result->getContent()) ?: '');
    }

    /**
     * @return FormUpdateDraft
     */
    private function tool(): FormUpdateDraft
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return new FormUpdateDraft(
            $features,
            $this->gateway,
            $this->createMock(ContentService::class),
            new FormRules($this->createMock(ComponentTree::class)),
            $this->createMock(DraftEditor::class)
        );
    }
}
