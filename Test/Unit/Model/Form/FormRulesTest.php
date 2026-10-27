<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Form;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type FormRow from FormGatewayInterface
 */
class FormRulesTest extends TestCase
{
    /**
     * @var ComponentTree
     */
    private ComponentTree $tree;

    /**
     * @var FormRules
     */
    private FormRules $rules;

    protected function setUp(): void
    {
        $field = ['require_parent' => true, 'content' => ['label' => ['type' => 'text']]];
        $components = [
            'hyva_form' => ['protected_root' => true, 'children' => ['config' => ['accepts' => ['form_step']]]],
            'form_step' => ['require_parent' => true, 'children' => true],
            'form_field_text' => $field,
            'form_field_email' => $field,
            'form_field_select' => $field,
            'form_field_multiselect' => $field,
        ];
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponents')->willReturn($components);
        $provider->method('getComponent')->willReturnCallback(static fn (string $n) => $components[$n] ?? null);
        $this->tree = new ComponentTree($provider);
        $this->rules = new FormRules($this->tree);
    }

    public function testPublishBlockersAreReportedBeforeHyvaRefuses(): void
    {
        $document = $this->form([
            ['form_field_text', ['label' => 'Name', 'field_name' => 'name']],
            ['form_field_email', ['label' => 'Email', 'field_name' => 'name']],
            ['form_field_text', ['label' => 'No name', 'field_name' => '']],
            ['form_field_text', ['label' => 'Bad name', 'field_name' => '1abc']],
            ['form_field_text', ['label' => 'Pattern', 'field_name' => 'code', 'pattern' => '[a-z']],
            ['form_field_select', ['label' => 'Size', 'field_name' => 'size', 'options' => [
                ['label' => 'S', 'value' => 's'],
            ], 'default_value' => 'xl']],
            ['form_field_select', ['label' => 'Empty', 'field_name' => 'empty', 'options' => []]],
        ]);

        $codes = array_column($this->rules->documentIssues($document), 'code');
        sort($codes);
        $this->assertSame([
            'default_not_an_option',
            'duplicate_field_name',
            'invalid_field_name',
            'invalid_pattern',
            'missing_field_name',
            'no_options',
        ], $codes);
        $this->assertSame([], $this->rules->documentIssues($this->form([
            ['form_field_text', ['label' => 'Name', 'field_name' => 'name', 'pattern' => '[A-Za-z ]+']],
        ])));
    }

    public function testPatternsAreDelimitedLikeHyva(): void
    {
        $this->assertSame([], $this->rules->documentIssues($this->form([
            ['form_field_text', ['label' => 'Ratio', 'field_name' => 'ratio', 'pattern' => '\d+\/\d+']],
            ['form_field_text', ['label' => 'Tilde', 'field_name' => 'tilde', 'pattern' => 'a~b']],
        ])));
    }

    public function testOptionDefaultsFollowHyva(): void
    {
        $options = [['label' => 'S', 'value' => 's'], ['label' => 'M', 'value' => 'm']];
        $this->assertSame([], $this->rules->documentIssues($this->form([
            ['form_field_multiselect', ['label' => 'Sizes', 'field_name' => 'sizes', 'options' => $options,
                'default_value' => 's, m']],
            ['form_field_select', ['label' => 'Flagged', 'field_name' => 'flagged', 'options' => [
                ['label' => 'S', 'value' => 's', 'default' => true],
            ], 'default_value' => 'xl']],
        ])));
        $issues = $this->rules->documentIssues($this->form([
            ['form_field_multiselect', ['label' => 'Sizes', 'field_name' => 'sizes', 'options' => $options,
                'default_value' => 's,xl']],
        ]));
        $this->assertSame(['default_not_an_option'], array_column($issues, 'code'));
    }

    public function testDeliveryIssues(): void
    {
        $nowhere = $this->row(['recipient_to' => '', 'webhook_url' => '', 'is_captcha_enabled' => false]);
        $this->assertSame(['no_destination', 'captcha_disabled'], array_column(
            $this->rules->deliveryIssues($nowhere, null),
            'code'
        ));

        $broken = $this->row([
            'recipient_to' => 'sales@example.com; oops',
            'recipient_bcc' => 'audit@example',
            'webhook_url' => 'http://192.168.1.10/hook',
            'webhook_format' => 'xml',
        ]);
        $codes = array_column($this->rules->deliveryIssues($broken, null), 'code');
        $this->assertSame(
            ['invalid_recipient', 'invalid_recipient', 'webhook_not_dispatchable', 'invalid_webhook_format'],
            $codes
        );

        $this->assertSame([], $this->rules->deliveryIssues($this->row([
            'recipient_to' => 'sales@example.com',
            'webhook_url' => 'https://hooks.example.com/forms',
        ]), null));
    }

    public function testWebhookRulesMatchHyvaDispatcher(): void
    {
        $refused = [
            'ftp://example.com/x',
            'https://localhost/x',
            'http://127.0.0.1/x',
            'http://[::1]/x',
            'http://10.0.0.5',
            '/relative',
        ];
        foreach ($refused as $url) {
            $this->assertNotNull($this->rules->webhookProblem($url), $url);
        }
        $this->assertNull($this->rules->webhookProblem('https://hooks.example.com/forms'));
    }

    public function testRoutingRulesMustPointAtRealFields(): void
    {
        $document = $this->form([['form_field_email', ['label' => 'Email', 'field_name' => 'email']]]);
        $emailUid = $this->rules->fields($document)[0]['uid'];
        $rootUid = Cast::string($this->tree->getRootNodes($document)[0]['uid'] ?? '');
        $document = $this->tree->setField($document, $rootUid, 'reply_to_field_uid', 'missing-uid');
        $document = $this->tree->setField($document, $rootUid, 'recipient_routing', [
            'field_uid' => $emailUid,
            'rules' => [['value' => 'x', 'recipients' => 'not-an-address']],
        ]);

        $codes = array_column(
            $this->rules->deliveryIssues($this->row(['recipient_to' => 'a@example.com']), $document),
            'code'
        );
        $this->assertSame(['reply_to_field_missing', 'invalid_routing_recipient'], $codes);
    }

    /**
     * Form document with one step holding the given fields.
     *
     * @param array $fields
     * @return array
     * @phpstan-param list<array{0: string, 1: array<string, mixed>}> $fields
     * @phpstan-return array<string, mixed>
     */
    private function form(array $fields): array
    {
        $nodes = array_map(fn (array $f): array => $this->tree->createNode($f[0], $f[1]), $fields);
        $root = $this->tree->createNode('hyva_form', [], [$this->tree->createNode('form_step', [], $nodes)]);
        return $this->tree->insertNode($this->tree->newDocument('form', 1), $root);
    }

    /**
     * Form row.
     *
     * @param array $overrides
     * @return array
     * @phpstan-param array<string, mixed> $overrides
     * @phpstan-return FormRow
     */
    private function row(array $overrides): array
    {
        /** @phpstan-var FormRow $row */
        $row = $overrides + [
            'id' => 1, 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true, 'store_ids' => [0],
            'recipient_to' => '', 'recipient_cc' => '', 'recipient_bcc' => '', 'subject_template' => '',
            'webhook_url' => '', 'webhook_format' => '', 'is_captcha_enabled' => true, 'preview_url_key' => '',
            'has_draft' => true, 'has_published' => true, 'updated_at' => '',
        ];
        return $row;
    }
}
