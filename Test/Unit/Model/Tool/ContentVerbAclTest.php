<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\AbstractEntityCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Block\BlockCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Preview;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Draft\DraftCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Page\PageCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Transfer\TransferExport;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release\MenuPublish;
use PHPUnit\Framework\TestCase;

/**
 * Argument-aware Hyvä verbs: reading a version needs version_history, copy_from needs view on the source type.
 */
class ContentVerbAclTest extends TestCase
{
    /**
     * @var ContentAcl
     */
    private ContentAcl $acl;

    protected function setUp(): void
    {
        $authorization = $this->createMock(ContentTypeAuthorization::class);
        $authorization->method('getResource')->willReturnCallback(
            static fn (string $type, string $verb): string => 'Hyva::' . $type . '_' . $verb
        );
        $this->acl = new ContentAcl($authorization);
    }

    public function testDraftCreateFromAVersionNeedsVersionHistory(): void
    {
        $tool = $this->withAcl(DraftCreate::class, DraftCreate::class);
        $this->assertSame(
            ['Hyva::cms_page_save', 'Hyva::cms_page_version_history'],
            $tool->getContextualAclResources(['entity_type' => 'cms_page', 'source' => 'version', 'version_id' => 3])
        );
        $this->assertSame(
            ['Hyva::cms_page_save'],
            $tool->getContextualAclResources(['entity_type' => 'cms_page', 'source' => 'published'])
        );
    }

    public function testExportWithVersionsNeedsVersionHistory(): void
    {
        $tool = $this->withAcl(TransferExport::class, TransferExport::class);
        $this->assertSame(
            ['Hyva::cms_block_export', 'Hyva::cms_block_version_history', 'Hyva_Cms::transfer'],
            $tool->getContextualAclResources(['entity_type' => 'cms_block', 'include_versions' => true])
        );
        $this->assertSame(
            ['Hyva::cms_block_export', 'Hyva_Cms::transfer'],
            $tool->getContextualAclResources(['entity_type' => 'cms_block', 'include_versions' => false])
        );
        $this->assertSame(
            ['Hyva::cms_block_export', 'Hyva_Cms::transfer'],
            $tool->getContextualAclResources(['entity_type' => 'cms_block'])
        );
    }

    public function testPreviewOfAVersionNeedsVersionHistory(): void
    {
        $tool = $this->withAcl(Preview::class, Preview::class);
        $this->assertSame(
            ['Hyva::cms_page_view', 'Hyva::cms_page_version_history'],
            $tool->getContextualAclResources(['entity_type' => 'cms_page', 'version_id' => 4])
        );
        $this->assertSame(['Hyva::cms_page_view'], $tool->getContextualAclResources(['entity_type' => 'cms_page']));
    }

    public function testPublishOfAVersionNeedsVersionHistory(): void
    {
        $tool = $this->withAcl(Publish::class, Publish::class);
        $this->assertSame(
            ['Hyva::cms_page_publish', 'Hyva::cms_page_version_history'],
            $tool->getContextualAclResources(['entity_type' => 'cms_page', 'version_id' => 4])
        );
        $this->assertSame(['Hyva::cms_page_publish'], $tool->getContextualAclResources(['entity_type' => 'cms_page']));

        $menu = $this->withAcl(MenuPublish::class, Publish::class);
        $this->assertSame(
            ['Hyva::menu_publish', 'Hyva::menu_version_history'],
            $menu->getContextualAclResources(['id' => 2, 'version_id' => 4])
        );
    }

    public function testCopyFromNeedsViewOnTheSourceType(): void
    {
        foreach ([PageCreate::class, BlockCreate::class] as $class) {
            $tool = $this->withAcl($class, AbstractEntityCreate::class);
            $this->assertSame(
                ['Hyva::menu_view'],
                $tool->getContextualAclResources(['copy_from' => ['entity_type' => 'menu', 'id' => 1]])
            );
            $this->assertSame(
                ['Hyva::cms_block_view'],
                $tool->getContextualAclResources(['copy_from' => ['entity_type' => 'cms_block', 'id' => 1]])
            );
            $this->assertSame([], $tool->getContextualAclResources(['title' => 'No copy']));
            $this->assertSame([], $tool->getContextualAclResources(['copy_from' => 'menu']));
            $this->assertSame([], $tool->getContextualAclResources(['copy_from' => ['entity_type' => 'product']]));
        }
    }

    /**
     * Real tool class with only its contentAcl dependency set.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param class-string $declaringClass Class that declares the private contentAcl property
     * @return T
     */
    private function withAcl(string $class, string $declaringClass): object
    {
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($declaringClass, 'contentAcl'))->setValue($tool, $this->acl);
        return $tool;
    }
}
