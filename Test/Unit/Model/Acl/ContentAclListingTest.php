<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Acl;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Preview;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Draft\DraftCreate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Version\VersionRestore;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageReplace;
use PHPUnit\Framework\TestCase;

/**
 * T-27: a generic content tool is listed only for a role that can use it on at least one content type.
 */
class ContentAclListingTest extends TestCase
{
    private const VIEWER = [
        'Hyva_Cms::cms',
        'Hyva_MediaGallery::asset_view',
        'Hyva::cms_page_view',
        'Hyva::cms_block_view',
        'Hyva::menu_view',
    ];

    /**
     * @param list<string>|null $granted Null = no MCP caller (CLI)
     * @param list<string> $mapped
     * @return ContentAcl
     */
    private function acl(?array $granted, array $mapped = ['cms_page', 'cms_block', 'menu']): ContentAcl
    {
        $authorization = $this->createMock(ContentTypeAuthorization::class);
        $authorization->method('getResource')->willReturnCallback(
            static fn (string $type, string $verb): string => 'Hyva::' . $type . '_' . $verb
        );
        $authorization->method('getContentTypes')->willReturn($mapped);
        $caller = $this->createMock(CallerContext::class);
        $caller->method('isAllowed')->willReturnCallback(
            static fn (string $resource): ?bool => $granted === null ? null : in_array($resource, $granted, true)
        );
        return new ContentAcl($authorization, $caller);
    }

    /**
     * @param class-string $class
     * @param class-string $declaringClass
     * @param ContentAcl $acl
     * @return UnderlyingAclAwareInterface
     */
    private function tool(string $class, string $declaringClass, ContentAcl $acl): UnderlyingAclAwareInterface
    {
        $tool = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($declaringClass, 'contentAcl'))->setValue($tool, $acl);
        $this->assertInstanceOf(UnderlyingAclAwareInterface::class, $tool);
        return $tool;
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function writeToolProvider(): array
    {
        return [
            'draft create' => [DraftCreate::class],
            'publish' => [Publish::class],
            'version restore' => [VersionRestore::class],
        ];
    }

    /**
     * @dataProvider writeToolProvider
     * @param class-string $class
     */
    public function testReadOnlyRoleGetsADeniedResourceForWriteTools(string $class): void
    {
        $acl = $this->acl(self::VIEWER);
        $tool = $this->tool($class, $class, $acl);
        $resource = (string) $tool->getUnderlyingAclResource();
        $this->assertNotContains($resource, self::VIEWER, 'a read-only role must not list ' . $class);
    }

    public function testReadOnlyRoleKeepsReadTools(): void
    {
        $tool = $this->tool(Preview::class, Preview::class, $this->acl(self::VIEWER));
        $this->assertSame('Hyva::cms_page_view', $tool->getUnderlyingAclResource());
    }

    public function testRoleWithOneTypeSeesTheWriteTool(): void
    {
        $acl = $this->acl(array_merge(self::VIEWER, ['Hyva::cms_block_publish']));
        $tool = $this->tool(Publish::class, Publish::class, $acl);
        $this->assertSame('Hyva::cms_block_publish', $tool->getUnderlyingAclResource());
    }

    public function testEveryVerbMustBeHeldOnTheSameType(): void
    {
        $acl = $this->acl(array_merge(self::VIEWER, ['Hyva::cms_page_save', 'Hyva::menu_version_history']));
        $tool = $this->tool(VersionRestore::class, VersionRestore::class, $acl);
        $this->assertSame('Hyva::cms_page_version_history', $tool->getUnderlyingAclResource());

        $acl = $this->acl(array_merge(self::VIEWER, ['Hyva::menu_save', 'Hyva::menu_version_history']));
        $tool = $this->tool(VersionRestore::class, VersionRestore::class, $acl);
        $this->assertSame('Hyva::menu_save', $tool->getUnderlyingAclResource());
    }

    public function testUnmappedTypeDoesNotCount(): void
    {
        $acl = $this->acl(array_merge(self::VIEWER, ['Hyva::menu_publish']), ['cms_page', 'cms_block']);
        $tool = $this->tool(Publish::class, Publish::class, $acl);
        $this->assertSame('Hyva::cms_page_publish', $tool->getUnderlyingAclResource());
    }

    public function testWithoutAnMcpCallerTheStaticResourceIsKept(): void
    {
        $tool = $this->tool(DraftCreate::class, DraftCreate::class, $this->acl(null));
        $this->assertSame('Hyva_Cms::cms', $tool->getUnderlyingAclResource());
    }

    public function testImageReplaceNeedsMediaViewAndASaveVerb(): void
    {
        $tool = $this->tool(ImageReplace::class, ImageReplace::class, $this->acl(self::VIEWER));
        $this->assertSame('Hyva::cms_page_save', $tool->getUnderlyingAclResource());

        $tool = $this->tool(ImageReplace::class, ImageReplace::class, $this->acl(['Hyva::cms_page_save']));
        $this->assertSame('Hyva_MediaGallery::asset_view', $tool->getUnderlyingAclResource());

        $acl = $this->acl(array_merge(self::VIEWER, ['Hyva::cms_block_save']));
        $tool = $this->tool(ImageReplace::class, ImageReplace::class, $acl);
        $this->assertSame('Hyva::cms_block_save', $tool->getUnderlyingAclResource());
    }
}
