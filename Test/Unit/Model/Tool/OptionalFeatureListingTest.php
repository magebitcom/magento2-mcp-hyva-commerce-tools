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
use Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Job\JobList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Worker\WorkerStatus;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit\AuditMenu;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplateList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form\FormList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaLinked\Group\GroupList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Asset\AssetSearch;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit\AuditAll;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit\AuditCms;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit\AuditMenu as MediaAuditMenu;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageFindUsage;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageReplace;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageUpdateAlt;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageValidate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Media\MediaStatus;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Optimization\OptimizationClear;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Optimization\OptimizationConfigGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Item\ItemUpdate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu\MenuStatus;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuPublish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Balance\BalanceGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaStoreCredit\Status as StoreCreditStatus;
use PHPUnit\Framework\TestCase;

/**
 * T-42, T-56: tools of a missing optional feature are hidden from tools/list; the status tools stay.
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
            'form list' => [FormList::class, FeatureCheckerInterface::FORM_BUILDER, 'Hyva_FormBuilder::form_view'],
            'email template list' => [
                TemplateList::class,
                FeatureCheckerInterface::EMAIL_TEMPLATES,
                'Hyva_EmailTemplates::email_template_view',
            ],
            'store credit balance' => [
                BalanceGet::class,
                FeatureCheckerInterface::STORE_CREDIT,
                'Hyva_StoreCredit::balance_view',
            ],
            'linked group list' => [
                GroupList::class,
                FeatureCheckerInterface::LINKED_PRODUCTS,
                'Hyva_LinkedProducts::group_view',
            ],
            'ai job list' => [JobList::class, FeatureCheckerInterface::AI_WORKER, 'Hyva_AiWorker::manage'],
            'media asset search' => [
                AssetSearch::class,
                FeatureCheckerInterface::MEDIA_GALLERY,
                'Hyva_MediaGallery::asset_view',
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

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function imageAndAuditToolProvider(): array
    {
        return [
            'image list' => [ImageList::class],
            'image get' => [ImageGet::class],
            'image find usage' => [ImageFindUsage::class],
            'image validate' => [ImageValidate::class],
            'audit all' => [AuditAll::class],
            'audit cms' => [AuditCms::class],
            'audit menu' => [MediaAuditMenu::class],
        ];
    }

    /**
     * T-56: the Image and Audit tools are listed with Hyva_MediaGallery::asset_view, which exists only with Media
     * Gallery enabled.
     *
     * @dataProvider imageAndAuditToolProvider
     * @param class-string $class
     */
    public function testImageAndAuditToolsHiddenWhenMediaGalleryMissing(string $class): void
    {
        $this->assertSame(
            'Hyva_MediaGallery::asset_view',
            $this->tool($class, FeatureCheckerInterface::MEDIA_GALLERY, true)->getUnderlyingAclResource()
        );
        $this->assertSame(
            ContentAcl::UNAVAILABLE_RESOURCE,
            $this->tool($class, FeatureCheckerInterface::MEDIA_GALLERY, false)->getUnderlyingAclResource()
        );
    }

    /**
     * T-56: the image write tools keep their content-type listing rule, but only while Media Gallery is enabled.
     */
    public function testImageWriteToolsHiddenWhenMediaGalleryMissing(): void
    {
        foreach ([ImageReplace::class, ImageUpdateAlt::class] as $class) {
            $acl = $this->createMock(ContentAcl::class);
            $acl->method('listingResource')->willReturn('Hyva_Cms::page_save');

            $enabled = $this->tool($class, FeatureCheckerInterface::MEDIA_GALLERY, true);
            (new \ReflectionProperty($class, 'contentAcl'))->setValue($enabled, $acl);
            $this->assertSame('Hyva_Cms::page_save', $enabled->getUnderlyingAclResource(), $class);

            $missing = $this->tool($class, FeatureCheckerInterface::MEDIA_GALLERY, false);
            (new \ReflectionProperty($class, 'contentAcl'))->setValue($missing, $acl);
            $this->assertSame(ContentAcl::UNAVAILABLE_RESOURCE, $missing->getUnderlyingAclResource(), $class);
        }
    }

    public function testTheStatusToolsStayListed(): void
    {
        $this->assertSame('Hyva_Cms::cms', (new \ReflectionClass(MenuStatus::class))
            ->newInstanceWithoutConstructor()->getUnderlyingAclResource());
        $this->assertSame('Hyva_MediaGallery::asset_view', $this->tool(
            MediaStatus::class,
            FeatureCheckerInterface::MEDIA_GALLERY,
            true
        )->getUnderlyingAclResource());
        $this->assertSame('Hyva_Cms::cms', $this->tool(
            MediaStatus::class,
            FeatureCheckerInterface::MEDIA_GALLERY,
            false
        )->getUnderlyingAclResource());
        $this->assertSame('Magento_Customer::customer', $this->tool(
            StoreCreditStatus::class,
            FeatureCheckerInterface::STORE_CREDIT,
            false
        )->getUnderlyingAclResource());
        $this->assertSame('Magento_Backend::system', $this->tool(
            WorkerStatus::class,
            FeatureCheckerInterface::AI_WORKER,
            false
        )->getUnderlyingAclResource());
        $this->assertSame('Hyva_AiWorker::config', $this->tool(
            WorkerStatus::class,
            FeatureCheckerInterface::AI_WORKER,
            true
        )->getUnderlyingAclResource());
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
        // PHP 8.3 initialises a readonly property only from the class that declares it.
        $declaring = (new \ReflectionProperty($class, 'featureChecker'))->getDeclaringClass()
            ->getProperty('featureChecker');
        $declaring->setValue($tool, $features);
        $this->assertInstanceOf(UnderlyingAclAwareInterface::class, $tool);
        return $tool;
    }
}
