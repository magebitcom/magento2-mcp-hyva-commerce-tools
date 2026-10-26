<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MediaGalleryGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Image\UsageResolver;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageChecker;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetResolve;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageFindUsage;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageValidate;
use PHPUnit\Framework\TestCase;

/**
 * image.find_usage, image.get (usage), image.validate and asset.resolve leave out pages, blocks and menus of other
 * websites' store views (finding: unscoped image/audit lists).
 */
class ImageUsageScopeTest extends TestCase
{
    use ContentScopeStub;
    use ToolPayloadTrait;

    private const PATH = 'wysiwyg/banner.jpg';

    public function testFindUsageListsOnlyTheRolesContent(): void
    {
        $result = $this->findUsage($this->scope([1]))->execute(['path' => self::PATH]);

        $this->assertSame([['cms_page', 1], ['product', 5]], $this->targets($this->arrayAt($result, 'usages')));
        $this->assertSame(2, $this->at($result, 'total'));
        $this->assertSame(['hyva_cms' => 1, 'product' => 1], $this->at($result, 'by_source'));
        $this->assertSame(2, $this->at($result, 'usages_hidden_by_permissions'));

        $all = $this->findUsage($this->scope(null))->execute(['path' => self::PATH]);
        $this->assertSame(4, $this->at($all, 'total'));
        $text = $all->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $payload);
    }

    public function testTheImageUsageFieldListsOnlyTheRolesContent(): void
    {
        $restricted = (new UsageResolver($this->usageIndex(), $this->scope([1])))->resolve(self::PATH, []);
        $this->assertIsArray($restricted);
        $this->assertSame(2, $restricted['total']);
        $this->assertSame(2, $restricted['usages_hidden_by_permissions']);

        $all = (new UsageResolver($this->usageIndex(), $this->scope(null)))->resolve(self::PATH, []);
        $this->assertIsArray($all);
        $this->assertSame(4, $all['total']);
        $this->assertArrayNotHasKey('usages_hidden_by_permissions', $all);
    }

    public function testValidateChecksOnlyTheRolesUsages(): void
    {
        $checked = [];
        $checker = $this->createMock(ImageChecker::class);
        $checker->method('check')->willReturnCallback(
            static function (string $path, array $usages) use (&$checked): array {
                $checked = $usages;
                return ['file' => ['path' => $path, 'exists' => true, 'size' => 1, 'width' => 1, 'height' => 1,
                    'mime' => 'image/jpeg', 'modified_at' => null, 'extension' => 'jpg'], 'issues' => []];
            }
        );
        $tool = new ImageValidate(
            $checker,
            $this->usageIndex(),
            $this->createMock(MediaService::class),
            $this->editor(),
            $this->scope([1])
        );

        $result = $tool->execute(['path' => self::PATH]);

        $this->assertSame([['cms_page', 1], ['product', 5]], $this->targets($checked));
        $this->assertSame(2, $this->at($result, 'usages_hidden_by_permissions'));
    }

    public function testAssetResolveListsOnlyTheRolesContent(): void
    {
        $result = $this->assetResolve($this->scope([1]))->execute(['id' => 3]);

        $this->assertSame(
            [['cms_page', 1], ['product', 5]],
            $this->targets($this->arrayAt($result, 'used_in.hyva_and_catalog'))
        );
        $this->assertSame(['cms_page', 'catalog_product'], array_column(
            $this->arrayAt($result, 'used_in.magento_tracked'),
            'entity_type'
        ));
        $this->assertSame(3, $this->at($result, 'usages_hidden_by_permissions'));

        $all = $this->assetResolve($this->scope(null))->execute(['id' => 3]);
        $this->assertCount(4, $this->arrayAt($all, 'used_in.hyva_and_catalog'));
        $this->assertCount(3, $this->arrayAt($all, 'used_in.magento_tracked'));
    }

    /**
     * Scope over page 1 (store view 1), page 2, block 4 and menu 3 (store view 2), product 5 (website 1).
     *
     * @param array|null $allowedStoreIds
     * @return ContentScopeFilter
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function scope(?array $allowedStoreIds): ContentScopeFilter
    {
        return $this->contentScope($allowedStoreIds, [
            'cms_page' => [1 => [1], 2 => [2]],
            'cms_block' => [4 => [2]],
            'menu' => [3 => [2]],
        ], null, ['product' => [5 => [1]]]);
    }

    /**
     * Usage index: the image is used in page 1, page 2, menu 3 and product 5.
     *
     * @return ImageUsageIndex
     */
    private function usageIndex(): ImageUsageIndex
    {
        $usages = [];
        foreach ([['hyva_cms', 'cms_page', 1], ['hyva_cms', 'cms_page', 2], ['menu', 'menu', 3],
            ['product', 'product', 5]] as [$source, $type, $id]) {
            $usages[] = ['source' => $source, 'entity_type' => $type, 'id' => $id, 'identifier' => $type . $id,
                'edition' => 'live', 'uid' => null, 'component' => null, 'field' => null, 'alt' => 'a',
                'has_alt' => true];
        }
        $index = $this->createMock(ImageUsageIndex::class);
        $index->method('usagesOf')->willReturn($usages);
        $index->method('usagesOfMany')->willReturn([self::PATH => $usages]);
        return $index;
    }

    /**
     * Find usage tool.
     *
     * @param ContentScopeFilter $scope
     * @return ImageFindUsage
     */
    private function findUsage(ContentScopeFilter $scope): ImageFindUsage
    {
        return new ImageFindUsage(
            $this->usageIndex(),
            $this->createMock(MediaFileInfo::class),
            $this->editor(),
            $scope
        );
    }

    /**
     * Asset resolve tool for asset 3, also tracked by Magento in page 1, block 4 (store view 2) and product 5.
     *
     * @param ContentScopeFilter $scope
     * @return AssetResolve
     */
    private function assetResolve(ContentScopeFilter $scope): AssetResolve
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $gallery = $this->createMock(MediaGalleryGatewayInterface::class);
        $gallery->method('getByIds')->willReturn([['id' => 3, 'path' => self::PATH, 'title' => '',
            'content_type' => 'image/jpeg', 'width' => 1, 'height' => 1, 'size' => 1, 'updated_at' => '']]);
        $gallery->method('magentoUsage')->willReturn([3 => [
            ['entity_type' => 'cms_page', 'entity_id' => '1', 'field' => 'content'],
            ['entity_type' => 'cms_block', 'entity_id' => '4', 'field' => 'content'],
            ['entity_type' => 'catalog_product', 'entity_id' => '5', 'field' => 'description'],
        ]]);
        $gallery->method('keywords')->willReturn([]);
        return new AssetResolve(
            $features,
            $gallery,
            $this->createMock(ImageReferenceExtractor::class),
            $this->usageIndex(),
            $this->createMock(CallerContext::class),
            $scope
        );
    }

    /**
     * Editor that keeps paths as given.
     *
     * @return ImageContentEditor
     */
    private function editor(): ImageContentEditor
    {
        $editor = $this->createMock(ImageContentEditor::class);
        $editor->method('normalize')->willReturnArgument(0);
        return $editor;
    }

    /**
     * Type and id of each usage.
     *
     * @param array $usages
     * @return array
     * @phpstan-param array<mixed> $usages
     * @phpstan-return list<array{mixed, mixed}>
     */
    private function targets(array $usages): array
    {
        $targets = [];
        foreach ($usages as $usage) {
            $this->assertIsArray($usage);
            $targets[] = [$usage['entity_type'] ?? null, $usage['id'] ?? null];
        }
        return $targets;
    }
}
