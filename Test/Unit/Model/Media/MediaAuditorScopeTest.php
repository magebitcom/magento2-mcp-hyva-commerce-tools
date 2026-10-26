<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Media\ImageChecker;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use PHPUnit\Framework\TestCase;

/**
 * The media audits (hyva_media.audit*, hyva_commerce.audit.media/menu, media health widget) only check usages a
 * restricted caller may see (finding: unscoped image/audit lists).
 */
class MediaAuditorScopeTest extends TestCase
{
    use ContentScopeStub;

    public function testARestrictedCallerAuditsOnlyUsagesInItsStoreViews(): void
    {
        $report = $this->auditor([1])->audit();

        $this->assertSame(2, $report['images_checked']);
        $this->assertSame(['wysiwyg/mixed.jpg', 'wysiwyg/product.jpg'], array_column($report['images'], 'path'));
        $this->assertSame(['cms_page:own'], $report['images'][0]['used_in']);
        $this->assertSame(1, $report['images'][0]['usages']);
        $this->assertSame(2, $report['usages_hidden_by_permissions'] ?? null);
    }

    public function testAnUnrestrictedCallerAuditsEveryUsage(): void
    {
        $report = $this->auditor(null)->audit();

        $this->assertSame(3, $report['images_checked']);
        $this->assertSame(['cms_page:own', 'cms_page:foreign'], $report['images'][1]['used_in']);
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $report);
    }

    /**
     * Auditor over three images: one used only by store view 2's page, one by both pages, one by a product.
     *
     * @param array|null $allowedStoreIds
     * @return MediaAuditor
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function auditor(?array $allowedStoreIds): MediaAuditor
    {
        $own = $this->usage('cms_page', 1, 'own');
        $foreign = $this->usage('cms_page', 2, 'foreign');
        $usageIndex = $this->createMock(ImageUsageIndex::class);
        $usageIndex->method('all')->willReturn([
            'wysiwyg/foreign.jpg' => [$foreign],
            'wysiwyg/mixed.jpg' => [$own, $foreign],
            'wysiwyg/product.jpg' => [['source' => 'product', 'entity_type' => 'product', 'id' => 5,
                'identifier' => 'sku'] + $own],
        ]);
        $checker = $this->createMock(ImageChecker::class);
        $checker->method('check')->willReturnCallback(static fn (string $path, array $usages): array => [
            'file' => ['path' => $path, 'exists' => true, 'size' => 1024, 'width' => 1, 'height' => 1,
                'mime' => 'image/jpeg', 'modified_at' => null, 'extension' => 'jpg'],
            'issues' => [['severity' => 'warning', 'code' => 'missing_alt', 'message' => count($usages) . ' usages']],
        ]);
        return new MediaAuditor(
            $usageIndex,
            $checker,
            $this->createMock(MediaService::class),
            $this->contentScope($allowedStoreIds, ['cms_page' => [1 => [1], 2 => [2]]])
        );
    }

    /**
     * Hyvä CMS usage.
     *
     * @param string $type
     * @param int $id
     * @param string $identifier
     * @return array
     * @phpstan-return array{source: string, entity_type: string, id: int, identifier: string, edition: string,
     *     uid: string|null, component: string|null, field: string|null, alt: string|null, has_alt: bool}
     */
    private function usage(string $type, int $id, string $identifier): array
    {
        return ['source' => 'hyva_cms', 'entity_type' => $type, 'id' => $id, 'identifier' => $identifier,
            'edition' => 'draft', 'uid' => 'u', 'component' => 'image', 'field' => 'image', 'alt' => null,
            'has_alt' => false];
    }
}
