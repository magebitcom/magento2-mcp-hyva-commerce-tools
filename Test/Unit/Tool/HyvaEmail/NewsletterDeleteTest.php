<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaEmail;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterDelete;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use PHPUnit\Framework\TestCase;

/**
 * T-107: hyva_email.newsletter.delete follows the T-22 delete pattern: confirm-gated, refused while a newsletter queued
 * from the template has not been sent unless "force": true, hidden without its permissions, refused for a
 * scope-restricted role. Sent and cancelled queues are history, not usage.
 */
class NewsletterDeleteTest extends TestCase
{
    use RefusalAssertTrait;
    use StoreScopeStub;

    /**
     * EmailGatewayInterface::deletionUsage() report of a newsletter with one scheduled queue and one sent queue.
     */
    private const IN_USE = ['used' => true, 'total' => 1, 'queue' => [
        ['queue_id' => 4, 'status' => 'not_sent', 'start_at' => '2026-11-10 08:00:00'],
    ], 'queue_history' => 1];

    private const UNUSED = ['used' => false, 'total' => 0, 'queue' => [], 'queue_history' => 2];

    public function testWithoutConfirmItPreviewsAndDeletesNothing(): void
    {
        $gateway = $this->gateway(self::UNUSED);
        $gateway->expects($this->never())->method('deleteNewsletter');

        $payload = $this->payload($this->tool($gateway)->execute(['id' => 12]));

        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['confirmation_required']);
        $this->assertSame('delete_newsletter_template', $payload['action']);
        $this->assertSame(12, $this->valueAt($payload, 'affected', 0, 'id'));
        $this->assertSame('mhp order', $this->valueAt($payload, 'affected', 0, 'code'));
        $this->assertFalse($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertFalse($this->valueAt($payload, 'details', 'force_required'));
        $this->assertStringContainsString('cannot be undone', Cast::string($payload['hint']));
    }

    public function testConfirmDeletesAnUnusedTemplate(): void
    {
        $gateway = $this->gateway(self::UNUSED);
        $gateway->expects($this->once())->method('deleteNewsletter')->with(12);

        $result = $this->tool($gateway)->execute(['id' => 12, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertFalse($result->isError());
        $this->assertTrue($payload['deleted']);
        $this->assertSame(12, $payload['id']);
        $this->assertFalse($payload['in_use']);
        $this->assertArrayNotHasKey('forced', $payload);
        $this->assertSame(
            ['entity_type' => 'newsletter_template', 'id' => 12, 'deleted' => true, 'in_use' => false],
            $result->getAuditSummary()
        );
    }

    public function testANewsletterWithAnUnsentQueueIsPreviewedAsInUse(): void
    {
        $gateway = $this->gateway(self::IN_USE);
        $gateway->expects($this->never())->method('deleteNewsletter');

        $payload = $this->payload($this->tool($gateway)->execute(['id' => 12]));

        $this->assertTrue($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertTrue($this->valueAt($payload, 'details', 'force_required'));
        $this->assertSame(4, $this->valueAt($payload, 'details', 'usage', 'queue', 0, 'queue_id'));
        $this->assertStringContainsString('still in use', Cast::string($payload['hint']));
    }

    public function testConfirmWithoutForceRefusesATemplateInUse(): void
    {
        $gateway = $this->gateway(self::IN_USE);
        $gateway->expects($this->never())->method('deleteNewsletter');

        $result = $this->tool($gateway)->execute(['id' => 12, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertSame(409, $payload['status']);
        $this->assertTrue($payload['force_required']);
        $this->assertSame(
            'Newsletter template "mhp order" (#12) is still in use (queue 1, details in usage). Nothing was '
                . 'deleted. To delete it anyway, call again with "confirm": true and "force": true; Magento deletes '
                . 'the queued newsletters with it, so they are never sent.',
            $payload['error']
        );
    }

    public function testConfirmWithForceDeletesATemplateInUseAndListsTheBrokenUsages(): void
    {
        $gateway = $this->gateway(self::IN_USE);
        $gateway->expects($this->once())->method('deleteNewsletter')->with(12);

        $result = $this->tool($gateway)->execute(['id' => 12, 'confirm' => true, 'force' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($payload['deleted']);
        $this->assertTrue($payload['in_use']);
        $this->assertTrue($payload['forced']);
        $this->assertSame(self::IN_USE, $payload['usage']);
        $this->assertStringContainsString(
            '(queue 1, details in usage). Magento deleted those queued newsletters with it; they will not be sent.',
            Cast::string($payload['hint'])
        );
        $this->assertTrue($result->getAuditSummary()['forced'] ?? null);
    }

    public function testARestrictedRoleIsRefusedWithReasonScope(): void
    {
        $gateway = $this->gateway(self::UNUSED);
        $gateway->expects($this->never())->method('deleteNewsletter');

        $tool = $this->tool($gateway, [1]);
        $refusal = $this->assertRefused(fn () => $tool->execute(['id' => 12, 'confirm' => true]), 'scope');

        $this->assertStringContainsString('may not change newsletter templates', $refusal->getMessage());
    }

    public function testWithoutTheNativeDeletePermissionItIsHiddenAndRefused(): void
    {
        $gateway = $this->gateway(self::UNUSED);
        $gateway->expects($this->never())->method('deleteNewsletter');
        $tool = $this->tool($gateway, null, ['Magento_Newsletter::template' => false]);

        // The core lists and runs the tool only for a role with this resource, which this role lacks.
        $this->assertSame('Magento_Newsletter::template', $tool->getUnderlyingAclResource());
        $refusal = $this->assertRefused(fn () => $tool->execute(['id' => 12, 'confirm' => true]), 'acl');
        $this->assertSame($tool->getUnderlyingAclResource(), $refusal->getData()['resource'] ?? null);
    }

    public function testASentQueueIsHistoryAndNotUsage(): void
    {
        $gateway = $this->gateway(self::UNUSED);
        $gateway->expects($this->never())->method('deleteNewsletter');

        $payload = $this->payload($this->tool($gateway)->execute(['id' => 12]));

        $this->assertFalse($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertSame(2, $this->valueAt($payload, 'details', 'usage', 'queue_history'));
        $this->assertStringContainsString('2 sent or cancelled queue', Cast::string($payload['hint']));
    }

    public function testItHasItsOwnResourceAndIsAWriteTool(): void
    {
        $tool = $this->tool($this->gateway(self::UNUSED));

        $this->assertSame('hyva_email.newsletter.delete', $tool->getName());
        $this->assertSame(
            'Magebit_McpHyvaCommerceTools::tool_hyva_email_newsletter_delete',
            NewsletterDelete::ACL_RESOURCE
        );
        $this->assertSame(WriteMode::WRITE, $tool->getWriteMode());
        // Hyvä has no newsletter delete verb: it is listed under Magento's newsletter template permission.
        $this->assertSame('Magento_Newsletter::template', $tool->getUnderlyingAclResource());
        $this->assertSame(
            'Magento_Newsletter::template',
            $this->tool($this->gateway(self::UNUSED), null, [], false)->getUnderlyingAclResource()
        );
    }

    public function testItIsHiddenWithoutTheEmailTemplatesModule(): void
    {
        $this->assertSame(
            ContentAcl::UNAVAILABLE_RESOURCE,
            $this->tool($this->gateway(self::UNUSED), null, [], true, false)->getUnderlyingAclResource()
        );
    }

    /**
     * Gateway with template 12 ("mhp order") and its usage report.
     *
     * @param array<string, mixed> $usage
     * @return EmailGatewayInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function gateway(array $usage): EmailGatewayInterface
    {
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->method('get')->willReturnCallback(static fn (string $type, int $id): ?array => $id === 12 ? [
            'id' => 12, 'type' => $type, 'code' => 'mhp order', 'subject' => 'Your order',
            'orig_template_code' => 'sales_email_order_template', 'sender_name' => '', 'sender_email' => '',
            'hyva_enabled' => true, 'has_draft' => true, 'has_published' => false, 'preheader' => '',
            'assigned_to' => [],
        ] : null);
        $gateway->method('deletionUsage')->willReturn($usage);
        return $gateway;
    }

    /**
     * Newsletter template delete tool.
     *
     * @param EmailGatewayInterface $gateway
     * @param list<int>|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param array<string, bool> $denied Resources the caller lacks (all others allowed).
     * @param bool $withCaller Whether an MCP caller is bound (false = CLI, isAllowed() null).
     * @param bool $enabled Whether Hyvä Email Templates is enabled.
     * @return NewsletterDelete
     */
    private function tool(
        EmailGatewayInterface $gateway,
        ?array $allowedStoreIds = null,
        array $denied = [],
        bool $withCaller = true,
        bool $enabled = true
    ): NewsletterDelete {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn($enabled);
        $features->method('getStatus')->willReturn([
            'feature' => 'email_templates', 'label' => 'Email Templates', 'available' => $enabled,
            'installed' => $enabled, 'enabled' => $enabled, 'version' => null, 'package' => '',
            'acl_resource' => null, 'acl_allowed' => null, 'modules' => [],
        ]);
        $caller = $this->createMock(CallerContext::class);
        $caller->method('isAllowed')->willReturnCallback(
            static fn (string $resource): ?bool => $withCaller ? !array_key_exists($resource, $denied) : null
        );
        return new NewsletterDelete(
            $features,
            $gateway,
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope($allowedStoreIds),
            $caller
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
