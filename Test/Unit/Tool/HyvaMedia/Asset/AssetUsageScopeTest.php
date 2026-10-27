<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Asset;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetMove;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetResolve;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetTrash;
use PHPUnit\Framework\TestCase;

/**
 * The asset.move and asset.trash previews and asset.resolve leave out usages in pages, blocks, menus, products and
 * categories outside the caller's websites/store views (finding: previews listed other store views' content).
 *
 * Asset 1 is used in page 1 (store view 1) and product 6 (website 2), tracked in category 5 (store view 2's tree)
 * and product 2 (website 1). Asset 2 is used only in page 2 (store view 2).
 */
class AssetUsageScopeTest extends TestCase
{
    use ContentScopeStub;
    use ToolPayloadTrait;

    public function testTheMovePreviewListsOnlyTheRolesUsages(): void
    {
        $result = $this->move($this->scope([1]))->execute(['ids' => [1, 2], 'target_folder' => 'wysiwyg/new']);

        $this->assertSame(
            [['cms_page', 1]],
            $this->targets($this->arrayAt($result, 'affected.assets.0.used_in.hyva_and_catalog'), 'id')
        );
        $this->assertSame(
            [['catalog_product', '2']],
            $this->targets($this->arrayAt($result, 'affected.assets.0.used_in.magento_tracked'), 'entity_id')
        );
        $this->assertSame(2, $this->payloadAt($result, 'affected.assets.0.usages_hidden_by_permissions'));
        $this->assertSame([], $this->payloadAt($result, 'affected.assets.1.used_in.hyva_and_catalog'));
        $this->assertSame(1, $this->payloadAt($result, 'affected.assets.1.usages_hidden_by_permissions'));
    }

    public function testTheTrashPreviewListsOnlyTheRolesUsagesButCountsEveryUseInInUse(): void
    {
        $result = $this->trash($this->scope([1]))->execute(['ids' => [1, 2]]);

        $this->assertSame(
            [['cms_page', 1]],
            $this->targets($this->arrayAt($result, 'affected.assets.0.used_in.hyva_and_catalog'), 'id')
        );
        $this->assertSame(
            [['catalog_product', '2']],
            $this->targets($this->arrayAt($result, 'affected.assets.0.used_in.magento_tracked'), 'entity_id')
        );
        $this->assertSame(2, $this->payloadAt($result, 'affected.assets.0.usages_hidden_by_permissions'));
        $this->assertSame([], $this->payloadAt($result, 'affected.assets.1.used_in.hyva_and_catalog'));
        $this->assertSame(1, $this->payloadAt($result, 'affected.assets.1.usages_hidden_by_permissions'));
        // Asset 2 is used only in store view 2's page: still in use.
        $this->assertSame(2, $this->payloadAt($result, 'affected.in_use'));
    }

    public function testAssetResolveLeavesOutProductsAndCategoriesOfOtherWebsites(): void
    {
        $tool = new AssetResolve(
            $this->features(),
            $this->gallery(),
            $this->createMock(ImageReferenceExtractor::class),
            $this->usageIndex(),
            $this->createMock(CallerContext::class),
            $this->scope([1])
        );

        $result = $tool->execute(['id' => 1]);

        $this->assertSame([['cms_page', 1]], $this->targets($this->arrayAt($result, 'used_in.hyva_and_catalog'), 'id'));
        $this->assertSame(
            [['catalog_product', '2']],
            $this->targets($this->arrayAt($result, 'used_in.magento_tracked'), 'entity_id')
        );
        $this->assertSame(2, $this->payloadAt($result, 'usages_hidden_by_permissions'));
    }

    public function testAnUnrestrictedCallerSeesEveryUsageWithoutACounter(): void
    {
        $move = $this->move($this->scope(null))->execute(['ids' => [1, 2], 'target_folder' => 'wysiwyg/new']);
        $trash = $this->trash($this->scope(null))->execute(['ids' => [1, 2]]);

        foreach ([$move, $trash] as $result) {
            $asset = $this->arrayAt($result, 'affected.assets.0');
            $this->assertArrayNotHasKey('usages_hidden_by_permissions', $asset);
            $this->assertCount(2, $this->arrayAt($result, 'affected.assets.0.used_in.hyva_and_catalog'));
            $this->assertCount(2, $this->arrayAt($result, 'affected.assets.0.used_in.magento_tracked'));
            $this->assertCount(1, $this->arrayAt($result, 'affected.assets.1.used_in.hyva_and_catalog'));
        }
        $this->assertSame(2, $this->payloadAt($trash, 'affected.in_use'));
    }

    /**
     * Scope over page 1 (store view 1), page 2 (store view 2), product 2 (website 1), product 6 (website 2) and
     * category 5 (store view 2's tree, root 12).
     *
     * @param array|null $allowedStoreIds
     * @return ContentScopeFilter
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function scope(?array $allowedStoreIds): ContentScopeFilter
    {
        return $this->contentScope(
            $allowedStoreIds,
            ['cms_page' => [1 => [1], 2 => [2]]],
            null,
            ['product' => [2 => [1], 6 => [2]], 'category' => [5 => '1/12/5']]
        );
    }

    /**
     * Move tool.
     *
     * @param ContentScopeFilter $scope
     * @return AssetMove
     */
    private function move(ContentScopeFilter $scope): AssetMove
    {
        return new AssetMove(
            $this->features(),
            $this->gallery(),
            $this->createMock(ImageReferenceExtractor::class),
            $this->usageIndex(),
            $this->createMock(CallerContext::class),
            $scope
        );
    }

    /**
     * Trash tool.
     *
     * @param ContentScopeFilter $scope
     * @return AssetTrash
     */
    private function trash(ContentScopeFilter $scope): AssetTrash
    {
        return new AssetTrash(
            $this->features(),
            $this->gallery(),
            $this->createMock(ImageReferenceExtractor::class),
            $this->usageIndex(),
            $this->createMock(CallerContext::class),
            $scope
        );
    }

    /**
     * Media Gallery available.
     *
     * @return FeatureCheckerInterface
     */
    private function features(): FeatureCheckerInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return $features;
    }

    /**
     * Gallery with assets 1 and 2; asset 1 is tracked by Magento in category 5 and product 2.
     *
     * @return MediaGalleryGatewayInterface
     */
    private function gallery(): MediaGalleryGatewayInterface
    {
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('getByIds')->willReturnCallback(static function (array $ids): array {
            $assets = [];
            foreach ($ids as $id) {
                $assets[] = ['id' => $id, 'path' => 'wysiwyg/' . $id . '.jpg', 'title' => '',
                    'content_type' => 'image/jpeg', 'width' => 1, 'height' => 1, 'size' => 1, 'updated_at' => ''];
            }
            return $assets;
        });
        $gallery->method('magentoUsage')->willReturn([1 => [
            ['entity_type' => 'catalog_category', 'entity_id' => '5', 'field' => 'description'],
            ['entity_type' => 'catalog_product', 'entity_id' => '2', 'field' => 'description'],
        ]]);
        $gallery->method('keywords')->willReturn([]);
        $gallery->expects($this->never())->method('move');
        $gallery->expects($this->never())->method('trash');
        return $gallery;
    }

    /**
     * Usage index: asset 1 in page 1 and product 6, asset 2 in page 2.
     *
     * @return ImageUsageIndex
     */
    private function usageIndex(): ImageUsageIndex
    {
        $usage = static fn (string $source, string $type, int $id): array => ['source' => $source,
            'entity_type' => $type, 'id' => $id, 'identifier' => $type . $id, 'edition' => 'live', 'uid' => null,
            'component' => null, 'field' => null, 'alt' => 'a', 'has_alt' => true];
        $index = $this->createMock(ImageUsageIndex::class);
        $index->method('usagesOfMany')->willReturn([
            'wysiwyg/1.jpg' => [$usage('hyva_cms', 'cms_page', 1), $usage('product', 'product', 6)],
            'wysiwyg/2.jpg' => [$usage('hyva_cms', 'cms_page', 2)],
        ]);
        return $index;
    }

    /**
     * Type and id of each usage.
     *
     * @param array $usages
     * @param string $idKey
     * @return array
     * @phpstan-param array<mixed> $usages
     * @phpstan-return list<array{mixed, mixed}>
     */
    private function targets(array $usages, string $idKey): array
    {
        $targets = [];
        foreach ($usages as $usage) {
            $this->assertIsArray($usage);
            $targets[] = [$usage['entity_type'] ?? null, $usage[$idKey] ?? null];
        }
        return $targets;
    }
}
