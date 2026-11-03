<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit\AuditMenu;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Media\MediaStatus;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Optimization\OptimizationClear;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Optimization\OptimizationConfigGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Item\ItemUpdate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuStatus;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuPublish;
use PHPUnit\Framework\TestCase;

/**
 * T-42: tools of a missing optional feature are hidden from tools/list; the status tools stay.
 */
class OptionalFeatureListingTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function featureToolProvider(): array
    {
        return [
            'menu list' => [MenuList::class, FeatureCheckerInterface::MENU_BUILDER, 'Hyva_MenuBuilder::menu_view'],
            'menu item update' => [
                ItemUpdate::class,
                FeatureCheckerInterface::MENU_BUILDER,
                'Hyva_MenuBuilder::menu_save',
            ],
            'menu audit' => [AuditMenu::class, FeatureCheckerInterface::MENU_BUILDER, 'Hyva_MenuBuilder::menu_view'],
            'optimization clear' => [
                OptimizationClear::class,
                FeatureCheckerInterface::MEDIA_OPTIMIZATION,
                'Hyva_MediaGallery::asset_save',
            ],
            'optimization config' => [
                OptimizationConfigGet::class,
                FeatureCheckerInterface::MEDIA_OPTIMIZATION,
                'Magento_Cms::config_cms',
            ],
        ];
    }

    /**
     * @dataProvider featureToolProvider
     * @param class-string $class
     * @param string $feature
     * @param string $resource
     */
    public function testAToolOfAMissingFeatureIsHidden(string $class, string $feature, string $resource): void
    {
        $this->assertSame($resource, $this->tool($class, $feature, true)->getUnderlyingAclResource());
        $this->assertSame(
            ContentAcl::UNAVAILABLE_RESOURCE,
            $this->tool($class, $feature, false)->getUnderlyingAclResource()
        );
    }

    public function testTheStatusToolsStayListed(): void
    {
        $this->assertSame('Hyva_Cms::cms', (new \ReflectionClass(MenuStatus::class))
            ->newInstanceWithoutConstructor()->getUnderlyingAclResource());
        $this->assertSame('Hyva_MediaGallery::asset_view', (new \ReflectionClass(MediaStatus::class))
            ->newInstanceWithoutConstructor()->getUnderlyingAclResource());
    }

    public function testAFixedTypeVariantIsHiddenWhileHyvaHasNoMappingForTheType(): void
    {
        $acl = $this->createMock(ContentAcl::class);
        $acl->method('resources')->willReturn(['Hyva_MenuBuilder::menu_publish']);
        $acl->method('isMapped')->willReturnOnConsecutiveCalls(true, false);
        $tool = (new \ReflectionClass(MenuPublish::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Publish::class, 'contentAcl'))->setValue($tool, $acl);

        $this->assertSame('Hyva_MenuBuilder::menu_publish', $tool->getUnderlyingAclResource());
        $this->assertSame(ContentAcl::UNAVAILABLE_RESOURCE, $tool->getUnderlyingAclResource());
    }

    /**
     * @param class-string $class
     * @param string $feature
     * @param bool $enabled
     * @return UnderlyingAclAwareInterface
     */
    private function tool(string $class, string $feature, bool $enabled): UnderlyingAclAwareInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('getStatus')->willReturnCallback(
            static fn (string $asked): array => [
                'feature' => $asked,
                'label' => $asked,
                'available' => $enabled || $asked !== $feature,
                'installed' => $enabled || $asked !== $feature,
                'enabled' => $enabled || $asked !== $feature,
                'version' => null,
                'package' => '',
                'acl_resource' => null,
                'acl_allowed' => null,
                'modules' => [],
            ]
        );
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $declaring = new \ReflectionProperty($class, 'featureChecker');
        $declaring->setValue($tool, $features);
        $this->assertInstanceOf(UnderlyingAclAwareInterface::class, $tool);
        return $tool;
    }
}
