<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block\BlockDelete;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageDelete;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use PHPUnit\Framework\TestCase;

/**
 * T-22: hyva_cms.page.delete / hyva_cms.block.delete are confirm-gated, need both delete permissions and the store
 * scope write rule. T-73: a page in use needs "force": true as well.
 */
class PageDeleteTest extends TestCase
{
    use StoreScopeStub;

    private const BOTH = ['Magento_Cms::page_delete', 'Hyva_Cms::page_delete'];

    /**
     * DeletionUsage::forPage() report of a page used as home page and linked once.
     */
    private const IN_USE = ['used' => true, 'total' => 2, 'store_config' => [['location' => 'home page',
        'path' => 'web/default/cms_home_page', 'scope' => 'stores', 'scope_id' => 1]], 'links' => [[
        'entity_type' => 'cms_block', 'id' => 4, 'identifier' => 'footer', 'title' => 'Footer', 'edition' => 'draft',
        'uid' => 'abc', 'component' => 'link']]];

    public function testWithoutConfirmNothingIsDeletedAndThePreviewNamesThePage(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('delete');

        $result = $this->tool($writer, self::BOTH)->execute(['id' => 7]);
        $payload = $this->payload($result);

        $this->assertFalse($result->isError());
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['confirmation_required']);
        $this->assertSame('delete_cms_page', $payload['action']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame(
            ['entity_type' => 'cms_page', 'id' => 7, 'identifier' => 'sale', 'title' => 'Sale', 'is_active' => true,
                'store_ids' => [1], 'in_use' => false],
            $this->valueAt($payload, 'affected', 0)
        );
        $this->assertFalse($this->valueAt($payload, 'details', 'in_use'));
        $this->assertStringContainsString('cannot be undone', Cast::string($payload['hint']));
    }

    public function testAPageInUseSaysSoInThePreview(): void
    {
        $payload = $this->payload($this->tool($this->createMock(EntityWriter::class), self::BOTH, null, self::IN_USE)
            ->execute(['id' => 7]));

        $this->assertTrue($this->valueAt($payload, 'affected', 0, 'in_use'));
        $this->assertTrue($this->valueAt($payload, 'details', 'in_use'));
        $this->assertSame('home page', $this->valueAt($payload, 'details', 'usage', 'store_config', 0, 'location'));
        $this->assertStringContainsString('still in use', Cast::string($payload['hint']));
        $this->assertTrue($this->valueAt($payload, 'details', 'force_required'));
        $this->assertStringContainsString('"force": true', Cast::string($payload['hint']));
    }

    public function testConfirmWithoutForceRefusesAPageInUseAndNamesTheUsages(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('delete');

        $result = $this->tool($writer, self::BOTH, null, self::IN_USE)->execute(['id' => 7, 'confirm' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($result->isError());
        $this->assertSame(409, $payload['status']);
        $this->assertTrue($payload['in_use']);
        $this->assertTrue($payload['force_required']);
        $this->assertSame('home page', $this->valueAt($payload, 'usage', 'store_config', 0, 'location'));
        $this->assertSame(
            'CMS page "sale" (#7) is still in use (store_config 1, links 1, details in usage). Nothing was deleted. '
                . 'To delete it anyway, call again with "confirm": true and "force": true; those references break.',
            $payload['error']
        );
    }

    public function testConfirmWithForceDeletesAPageInUse(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('delete')->with('cms_page', 7);

        $payload = $this->payload($this->tool($writer, self::BOTH, null, self::IN_USE)
            ->execute(['id' => 7, 'confirm' => true, 'force' => true]));

        $this->assertTrue($payload['deleted']);
        $this->assertTrue($payload['in_use']);
    }

    public function testAForcedDeleteSaysSoAndListsTheUsagesThatBroke(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('delete')->with('cms_page', 7);

        $result = $this->tool($writer, self::BOTH, null, self::IN_USE)
            ->execute(['id' => 7, 'confirm' => true, 'force' => true]);
        $payload = $this->payload($result);

        $this->assertFalse($result->isError());
        $this->assertTrue($payload['forced']);
        // The same report as the preview's details.usage.
        $this->assertSame(self::IN_USE, $payload['usage']);
        $this->assertSame(
            'Deleted with "force": true while it was still in use (store_config 1, links 1, details in usage). '
                . 'Those references are now broken: remove or replace them.',
            $payload['hint']
        );
        $this->assertTrue($result->getAuditSummary()['forced'] ?? false);
    }

    public function testADeleteOfAPageNotInUseIsNotMarkedForced(): void
    {
        $result = $this->tool($this->createMock(EntityWriter::class), self::BOTH)
            ->execute(['id' => 7, 'confirm' => true, 'force' => true]);
        $payload = $this->payload($result);

        $this->assertTrue($payload['deleted']);
        $this->assertArrayNotHasKey('forced', $payload);
        $this->assertArrayNotHasKey('usage', $payload);
        $this->assertArrayNotHasKey('hint', $payload);
        $this->assertArrayNotHasKey('forced', $result->getAuditSummary());
    }

    public function testForceWithoutConfirmOnlyPreviews(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('delete');

        $payload = $this->payload($this->tool($writer, self::BOTH, null, self::IN_USE)
            ->execute(['id' => 7, 'force' => true]));

        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($this->valueAt($payload, 'details', 'force_required'));
    }

    public function testTheSchemaOffersForceNextToConfirm(): void
    {
        $schema = $this->tool($this->createMock(EntityWriter::class), self::BOTH)->getInputSchema();

        $this->assertSame('boolean', $this->valueAt($schema, 'properties', 'force', 'type'));
        $this->assertSame('boolean', $this->valueAt($schema, 'properties', 'confirm', 'type'));
    }

    public function testConfirmDeletesThroughTheRepositoryWriter(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('delete')->with('cms_page', 7);

        $payload = $this->payload($this->tool($writer, self::BOTH)->execute(['id' => 7, 'confirm' => true]));

        $this->assertTrue($payload['deleted']);
        $this->assertSame(7, $payload['id']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function missingPermissionProvider(): array
    {
        return [
            'no Magento delete' => [['Hyva_Cms::page_delete'], 'Magento_Cms::page_delete'],
            'no Hyvä delete' => [['Magento_Cms::page_delete'], 'Hyva_Cms::page_delete'],
        ];
    }

    /**
     * @dataProvider missingPermissionProvider
     * @param list<string> $granted
     * @param string $missing
     */
    public function testBothDeletePermissionsAreRequired(array $granted, string $missing): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('delete');
        $tool = $this->tool($writer, $granted);

        // The core gate refuses the call on the listing resource...
        $this->assertSame($missing, $tool->getUnderlyingAclResource());
        // ...and the tool refuses it again.
        $result = $tool->execute(['id' => 7, 'confirm' => true]);
        $this->assertTrue($result->isError());
        $text = json_encode($result->getContent()) ?: '';
        $this->assertStringContainsString('403', $text);
        $this->assertStringContainsString(
            'Your admin role may not delete CMS pages: it needs the ' . $missing . ' permission.',
            $text
        );
    }

    public function testARoleWithBothPermissionsIsListedWithTheHyvaVerb(): void
    {
        $this->assertSame(
            'Hyva_Cms::page_delete',
            $this->tool($this->createMock(EntityWriter::class), self::BOTH)->getUnderlyingAclResource()
        );
    }

    public function testARestrictedRoleMayNotDeleteAPageSharedWithAnotherStoreView(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('delete');

        $result = $this->tool($writer, self::BOTH, [1], null, [1, 2])->execute(['id' => 7, 'confirm' => true]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('403', json_encode($result->getContent()) ?: '');
    }

    public function testARestrictedRoleDeletesAPageOfItsOwnStoreView(): void
    {
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->once())->method('delete');

        $result = $this->tool($writer, self::BOTH, [1])->execute(['id' => 7, 'confirm' => true]);

        $this->assertFalse($result->isError());
    }

    public function testTheBlockToolUsesTheBlockPermissions(): void
    {
        $this->assertSame('Magento_Cms::block', BlockDelete::ADMIN_DELETE_RESOURCE);
        $this->assertSame('Hyva_Cms::block_delete', BlockDelete::HYVA_DELETE_RESOURCE);
        $this->assertSame('Magebit_McpHyvaCommerceTools::tool_hyva_cms_block_delete', BlockDelete::ACL_RESOURCE);
        $this->assertSame(
            WriteMode::WRITE,
            (new \ReflectionClass(BlockDelete::class))->newInstanceWithoutConstructor()->getWriteMode()
        );
    }

    public function testThePermissionRefusalNamesTheBlockTypeInThePlural(): void
    {
        $caller = $this->createMock(CallerContext::class);
        $caller->method('isAllowed')->willReturn(false);
        $writer = $this->createMock(EntityWriter::class);
        $writer->expects($this->never())->method('delete');
        $tool = new BlockDelete(
            $this->createMock(ContentLocator::class),
            new ContentAcl($this->createMock(ContentTypeAuthorization::class), $caller),
            $caller,
            $this->createMock(DeletionUsage::class),
            $writer
        );

        $text = json_encode($tool->execute(['id' => 5, 'confirm' => true])->getContent()) ?: '';

        $this->assertStringContainsString(
            'Your admin role may not delete CMS blocks: it needs the Magento_Cms::block permission.',
            $text
        );
    }

    /**
     * Page delete over page 7 ("sale").
     *
     * @param EntityWriter $writer
     * @param list<string> $granted Resources the caller's role holds.
     * @param list<int>|null $allowedStoreIds Store views of a restricted caller; null = unrestricted.
     * @param array<string, mixed>|null $usage DeletionUsage::forPage() report; null = unused.
     * @param list<int> $storeIds Store views of the page.
     * @return PageDelete
     */
    private function tool(
        EntityWriter $writer,
        array $granted,
        ?array $allowedStoreIds = null,
        ?array $usage = null,
        array $storeIds = [1]
    ): PageDelete {
        $page = ['type' => 'cms_page', 'id' => 7, 'identifier' => 'sale', 'title' => 'Sale', 'is_active' => true,
            'store_ids' => $storeIds];
        $guard = $this->storeScope($allowedStoreIds);
        $locator = $this->createMock(ContentLocator::class);
        // The locator's write check, over the real guard.
        $locator->method('locateForWrite')->willReturnCallback(
            static function () use ($guard, $page): array {
                $guard->assertChangeable('CMS page #7', $page['store_ids'], null);
                return $page;
            }
        );
        $caller = $this->createMock(CallerContext::class);
        $caller->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => in_array($resource, $granted, true)
        );
        $authorization = $this->createMock(ContentTypeAuthorization::class);
        $authorization->method('getResource')->willReturnCallback(
            static fn (string $type, string $verb): string => 'Hyva_Cms::' . substr($type, 4) . '_' . $verb
        );
        $authorization->method('getContentTypes')->willReturn(['cms_page', 'cms_block']);
        $deletionUsage = $this->createMock(DeletionUsage::class);
        $deletionUsage->method('forPage')->willReturn(
            $usage ?? ['used' => false, 'total' => 0, 'store_config' => [], 'links' => []]
        );
        return new PageDelete(
            $locator,
            new ContentAcl($authorization, $caller),
            $caller,
            $deletionUsage,
            $writer
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
