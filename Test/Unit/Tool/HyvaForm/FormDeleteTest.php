<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaForm;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form\FormDelete;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T-181: hyva_form.form.delete follows the T-22 delete pattern: confirm-gated, refused while a form widget names the
 * form unless "force": true, listed with Form Builder's delete permission, hidden without Form Builder, refused for a
 * role outside the form's store views.
 */
class FormDeleteTest extends TestCase
{
    use RefusalAssertTrait;
    use StoreScopeStub;

    /**
     * DeletionUsage::forForm() report of a form a Hyvä CMS page embeds.
     */
    private const IN_USE = ['used' => true, 'hyva_content' => [
        ['entity_type' => 'cms_page', 'id' => 5, 'identifier' => 'contact-us', 'title' => 'Contact us',
            'edition' => 'published', 'uid' => 'abc', 'component' => 'hyva_form_widget'],
    ], 'magento_content' => [], 'widget_instances' => [], 'total' => 1];

    private const UNUSED = ['used' => false, 'hyva_content' => [], 'magento_content' => [], 'widget_instances' => [],
        'total' => 0];

    public function testWithoutConfirmItPreviewsAndDeletesNothing(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->never())->method('delete');

        $payload = $this->payload($this->tool($gateway, self::UNUSED)->execute(['id' => 3]));

        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['confirmation_required']);
        $this->assertSame('delete_form', $payload['action']);
        $this->assertSame(3, $this->valueAt($payload, 'affected', 0, 'id'));
        $this->assertSame('contact', $this->valueAt($payload, 'affected', 0, 'identifier'));
        $this->assertSame([1], $this->valueAt($payload, 'affected', 0, 'store_ids'));
        $this->assertFalse($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertFalse($this->valueAt($payload, 'details', 'force_required'));
        $this->assertNull($this->valueAt($payload, 'details', 'identifier_shared_with'));
        $this->assertStringContainsString('cannot be undone', Cast::string($payload['hint']));
    }

    public function testConfirmDeletesAnUnusedForm(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->once())->method('delete')->with(3);

        $result = $this->tool($gateway, self::UNUSED)->execute(['id' => 3, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertFalse($result->isError());
        $this->assertTrue($payload['deleted']);
        $this->assertSame(3, $payload['id']);
        $this->assertFalse($payload['in_use']);
        $this->assertArrayNotHasKey('forced', $payload);
        $this->assertSame(
            ['entity_type' => 'form', 'id' => 3, 'deleted' => true, 'in_use' => false],
            $result->getAuditSummary()
        );
    }

    public function testAFormAWidgetNamesIsPreviewedAsInUse(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->never())->method('delete');

        $payload = $this->payload($this->tool($gateway, self::IN_USE)->execute(['identifier' => 'contact']));

        $this->assertTrue($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertTrue($this->valueAt($payload, 'details', 'force_required'));
        $this->assertSame(5, $this->valueAt($payload, 'details', 'usage', 'hyva_content', 0, 'id'));
        $this->assertStringContainsString('still in use', Cast::string($payload['hint']));
    }

    public function testConfirmWithoutForceRefusesAFormInUse(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->never())->method('delete');

        $result = $this->tool($gateway, self::IN_USE)->execute(['id' => 3, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertSame(409, $payload['status']);
        $this->assertTrue($payload['force_required']);
        $this->assertSame(
            'Form "contact" (#3) is still in use (hyva_content 1, details in usage). Nothing was deleted. To delete '
                . 'it anyway, call again with "confirm": true and "force": true; a form widget that names a deleted '
                . 'form renders nothing.',
            $payload['error']
        );
    }

    public function testConfirmWithForceDeletesAFormInUseAndListsTheBrokenUsages(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->once())->method('delete')->with(3);

        $result = $this->tool($gateway, self::IN_USE)->execute(['id' => 3, 'confirm' => true, 'force' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($payload['deleted']);
        $this->assertTrue($payload['in_use']);
        $this->assertTrue($payload['forced']);
        $this->assertSame(self::IN_USE, $payload['usage']);
        $this->assertStringContainsString(
            '(hyva_content 1, details in usage). Those references are now broken',
            Cast::string($payload['hint'])
        );
        $this->assertTrue($result->getAuditSummary()['forced'] ?? null);
    }

    public function testAFormSharingItsIdentifierOnAnotherStoreViewIsNamedInThePreview(): void
    {
        $gateway = $this->gateway([$this->form(3, [1]), $this->form(4, [2])]);
        $gateway->expects($this->never())->method('delete');

        $payload = $this->payload($this->tool($gateway, self::IN_USE)->execute(['id' => 3]));

        $this->assertSame(
            [['id' => 4, 'store_ids' => [2]]],
            $this->valueAt($payload, 'details', 'identifier_shared_with')
        );
        $this->assertStringContainsString('Form(s) #4 use the same identifier', Cast::string($payload['hint']));
        // A restricted caller is not told about a form outside its store views.
        $restricted = $this->payload($this->tool($gateway, self::IN_USE, [1])->execute(['id' => 3]));
        $this->assertNull($this->valueAt($restricted, 'details', 'identifier_shared_with'));
    }

    public function testAFormOutsideTheCallersStoreViewsIsRefusedWithReasonScope(): void
    {
        $gateway = $this->gateway([$this->form(3, [2])]);
        $gateway->expects($this->never())->method('delete');

        $tool = $this->tool($gateway, self::UNUSED, [1]);
        $this->assertRefused(fn () => $tool->execute(['id' => 3, 'confirm' => true]), 'scope');
    }

    public function testAFormOfAllStoreViewsNeedsEveryStoreView(): void
    {
        $gateway = $this->gateway([$this->form(3, [0])]);
        $gateway->expects($this->never())->method('delete');

        $tool = $this->tool($gateway, self::UNUSED, [1]);
        $this->assertRefused(fn () => $tool->execute(['id' => 3, 'confirm' => true]), 'scope');
    }

    public function testAFormOfTheCallersStoreViewIsDeleted(): void
    {
        $gateway = $this->gateway();
        $gateway->expects($this->once())->method('delete')->with(3);

        $payload = $this->payload($this->tool($gateway, self::UNUSED, [1])->execute(['id' => 3, 'confirm' => true]));
        $this->assertTrue($payload['deleted']);
    }

    public function testItHasItsOwnResourceAndIsListedWithTheFormBuilderDeletePermission(): void
    {
        $tool = $this->tool($this->gateway(), self::UNUSED);

        $this->assertSame('hyva_form.form.delete', $tool->getName());
        $this->assertSame('Magebit_McpHyvaCommerceTools::tool_hyva_form_form_delete', FormDelete::ACL_RESOURCE);
        $this->assertSame('Hyva_FormBuilder::form_delete', $tool->getUnderlyingAclResource());
        $this->assertSame(WriteMode::WRITE, $tool->getWriteMode());
        $schema = $tool->getInputSchema();
        $this->assertIsArray($schema['properties'] ?? null);
        $this->assertSame(
            ['id', 'identifier', 'store_id', 'confirm', 'force'],
            array_keys($schema['properties'])
        );
    }

    public function testItIsHiddenWithoutFormBuilder(): void
    {
        $this->assertSame(
            ContentAcl::UNAVAILABLE_RESOURCE,
            $this->tool($this->gateway(), self::UNUSED, null, false)->getUnderlyingAclResource()
        );
    }

    /**
     * Gateway holding the forms (default: form 3 "contact" on store view 1).
     *
     * @param list<array<string, mixed>>|null $forms
     * @return FormGatewayInterface&MockObject
     */
    private function gateway(?array $forms = null): FormGatewayInterface
    {
        $forms ??= [$this->form(3, [1])];
        $gateway = $this->createMock(FormGatewayInterface::class);
        $gateway->method('get')->willReturnCallback(static function (int $id) use ($forms): ?array {
            foreach ($forms as $form) {
                if ($form['id'] === $id) {
                    return $form;
                }
            }
            return null;
        });
        $gateway->method('findByIdentifier')->willReturnCallback(
            static fn (string $identifier): array => array_values(array_filter(
                $forms,
                static fn (array $form): bool => $form['identifier'] === $identifier
            ))
        );
        return $gateway;
    }

    /**
     * Form row with identifier "contact".
     *
     * @param int $id
     * @param list<int> $storeIds
     * @return array<string, mixed>
     */
    private function form(int $id, array $storeIds): array
    {
        return [
            'id' => $id, 'identifier' => 'contact', 'title' => 'Contact', 'is_active' => true,
            'store_ids' => $storeIds, 'recipient_to' => 'shop@example.com', 'recipient_cc' => '',
            'recipient_bcc' => '', 'subject_template' => '', 'webhook_url' => '', 'webhook_format' => 'json',
            'is_captcha_enabled' => false, 'preview_url_key' => '', 'has_draft' => true, 'has_published' => true,
            'updated_at' => '',
        ];
    }

    /**
     * Form delete tool.
     *
     * @param FormGatewayInterface $gateway
     * @param array<string, mixed> $usage DeletionUsage::forForm() report.
     * @param list<int>|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param bool $enabled Whether Form Builder is enabled.
     * @return FormDelete
     */
    private function tool(
        FormGatewayInterface $gateway,
        array $usage,
        ?array $allowedStoreIds = null,
        bool $enabled = true
    ): FormDelete {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($enabled);
        $features->method('getStatus')->willReturn([
            'feature' => 'form_builder', 'label' => 'Form Builder', 'available' => $enabled,
            'installed' => $enabled, 'enabled' => $enabled, 'version' => null, 'package' => '',
            'acl_resource' => null, 'acl_allowed' => null, 'modules' => [],
        ]);
        $deletionUsage = $this->createMock(DeletionUsage::class);
        $deletionUsage->method('forForm')->with('contact')->willReturn($usage);
        return new FormDelete(
            $features,
            $gateway,
            $this->createMock(ContentService::class),
            new FormRules($this->createMock(ComponentTree::class)),
            $this->storeScope($allowedStoreIds),
            $deletionUsage
        );
    }

    /**
     * Decoded JSON payload of a tool result.
     *
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        return $payload;
    }

    /**
     * Value at a key path of a decoded payload, or null.
     *
     * @param mixed $value
     * @param int|string ...$keys
     * @return mixed
     */
    private function valueAt(mixed $value, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }
        return $value;
    }
}
