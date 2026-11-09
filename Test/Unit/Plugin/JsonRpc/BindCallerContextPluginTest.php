<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Plugin\JsonRpc;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolRegistryInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Model\Config\ModuleConfig;
use Magebit\Mcp\Model\JsonRpc\Handler\ToolsListHandler;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\Mcp\Model\Tool\DisabledTools;
use Magebit\Mcp\Model\Tool\SchemaSanitizer;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\Mcp\Model\Util\ToolDomain;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Plugin\JsonRpc\BindCallerContextPlugin;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * F2: the core's real tools/list hides the content write tools from a read-only role once the caller is bound.
 *
 * Runs Magebit\Mcp\Model\JsonRpc\Handler\ToolsListHandler with this module's real CallerContext, ContentAcl and
 * content tools from etc/di.xml; only the role's ACL answers and the Hyvä type mapping are stubbed.
 */
class BindCallerContextPluginTest extends TestCase
{
    /**
     * Hyvä Content Viewer: the CMS menu, media view and the view verb on every type.
     */
    private const VIEWER = [
        'Hyva_Cms::cms',
        'Hyva_MediaGallery::asset_view',
        'Hyva::cms_page_view',
        'Hyva::cms_block_view',
        'Hyva::menu_view',
    ];

    /**
     * @var list<ToolInterface>
     */
    private array $tools = [];

    /**
     * @var ToolsListHandler
     */
    private ToolsListHandler $handler;

    /**
     * @var BindCallerContextPlugin
     */
    private BindCallerContextPlugin $plugin;

    protected function setUp(): void
    {
        $aclChecker = $this->createMock(AclChecker::class);
        $aclChecker->method('isAllowed')->willReturnCallback(
            // The role holds every MCP tool resource of this module; the Hyvä resources are the viewer's only.
            static fn (User $user, string $resource): bool => str_starts_with($resource, 'Magebit_')
                || in_array($resource, self::VIEWER, true)
        );
        $contextProvider = new AuthenticatedContextProvider();
        $authorization = $this->createMock(ContentTypeAuthorization::class);
        $authorization->method('getResource')->willReturnCallback(
            static fn (string $type, string $verb): string => 'Hyva::' . $type . '_' . $verb
        );
        $authorization->method('getContentTypes')->willReturn(['cms_page', 'cms_block', 'menu']);
        $authorization->method('isMapped')->willReturn(true);
        $contentAcl = new ContentAcl($authorization, new CallerContext($contextProvider, $aclChecker));
        $this->tools = $this->contentTools($contentAcl);

        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('all')->willReturnCallback(fn (): array => $this->tools);
        $config = $this->createMock(ModuleConfig::class);
        $config->method('isAllowWrites')->willReturn(true);
        $sanitizer = $this->createMock(SchemaSanitizer::class);
        $sanitizer->method('sanitizeForClient')->willReturnArgument(1);
        $domain = $this->createMock(ToolDomain::class);
        $domain->method('prefixTitle')->willReturnArgument(1);
        $disabled = $this->createMock(DisabledTools::class);
        $disabled->method('isDisabled')->willReturn(false);
        $this->handler = new ToolsListHandler(
            $registry,
            $aclChecker,
            $config,
            $sanitizer,
            $this->createMock(LoggerInterface::class),
            $domain,
            $disabled
        );
        $this->plugin = new BindCallerContextPlugin(new CallerContext($contextProvider, $aclChecker));
    }

    public function testReadOnlyRoleDoesNotSeeContentWriteTools(): void
    {
        $request = new Request(1, false, 'tools/list', []);
        $context = $this->viewerContext();

        $this->plugin->beforeHandle($this->handler, $request, $context);
        $listed = $this->listedNames($this->handler->handle($request, $context)->result);

        $this->assertNotEmpty($this->writeToolNames());
        $this->assertSame([], array_values(array_intersect($this->writeToolNames(), $listed)));
        // Read tools that need only the view verb stay listed.
        $this->assertContains('hyva_cms_preview', $listed);
        $this->assertContains('hyva_cms_component_validate', $listed);
    }

    public function testWithoutTheBoundCallerTheGenericWriteToolsLeak(): void
    {
        // The defect the plugin fixes: the core sets no caller for tools/list, so the generic tools fall back to
        // Hyva_Cms::cms / Hyva_MediaGallery::asset_view, which a viewer holds (fixed-type variants stay hidden).
        $request = new Request(1, false, 'tools/list', []);
        $listed = $this->listedNames($this->handler->handle($request, $this->viewerContext())->result);
        $leaked = array_values(array_intersect($this->writeToolNames(), $listed));

        $this->assertContains('hyva_cms_publish', $leaked);
        $this->assertContains('hyva_cms_import', $leaked);
        $this->assertGreaterThanOrEqual(9, count($leaked));
    }

    public function testThePluginIsDeclaredOnTheListAndCallHandlers(): void
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 4) . '/etc/frontend/di.xml'));
        foreach (['ToolsListHandler', 'ToolsCallHandler'] as $handler) {
            $plugins = $xml->xpath(sprintf(
                '//type[@name="Magebit\\Mcp\\Model\\JsonRpc\\Handler\\%s"]/plugin[@type="%s"]',
                $handler,
                BindCallerContextPlugin::class
            ));
            $this->assertCount(1, $plugins ?: [], $handler);
        }
    }

    /**
     * @return AuthenticatedContext
     */
    private function viewerContext(): AuthenticatedContext
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getScopes')->willReturn(null);
        $token->method('getAllowWrites')->willReturn(true);
        return new AuthenticatedContext($token, $this->createMock(User::class));
    }

    /**
     * Tools from etc/di.xml whose listing resource comes from ContentAcl, with that ContentAcl injected.
     *
     * @param ContentAcl $contentAcl
     * @return list<ToolInterface>
     */
    private function contentTools(ContentAcl $contentAcl): array
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 4) . '/etc/di.xml'));
        $items = $xml->xpath(
            '//type[@name="Magebit\\Mcp\\Model\\Tool\\ToolRegistry"]/arguments/argument[@name="tools"]/item'
        );
        $tools = [];
        foreach ($items ?: [] as $item) {
            /** @var class-string<ToolInterface> $class */
            $class = trim((string) $item);
            $reflection = new \ReflectionClass($class);
            $property = $this->contentAclProperty($reflection);
            if ($property === null) {
                continue;
            }
            $tool = $reflection->newInstanceWithoutConstructor();
            $property->setValue($tool, $contentAcl);
            if ($tool instanceof AbstractMediaTool) {
                // Media Gallery installed and enabled, so the image write tools keep their content-type rule (T-56).
                (new \ReflectionProperty(AbstractMediaTool::class, 'featureChecker'))
                    ->setValue($tool, $this->enabledFeatures());
            }
            try {
                $tool->getDescription();
                $tool->getInputSchema();
                if ($tool instanceof UnderlyingAclAwareInterface) {
                    $tool->getUnderlyingAclResource();
                }
            } catch (\Error) {
                continue; // Needs more constructor dependencies (e.g. a feature check); the listing logic is the same.
            }
            $tools[] = $tool;
        }
        $this->assertGreaterThanOrEqual(15, count($tools));
        return $tools;
    }

    /**
     * Feature checker reporting every optional Hyvä feature as installed and enabled.
     *
     * @return FeatureCheckerInterface
     */
    private function enabledFeatures(): FeatureCheckerInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('getStatus')->willReturnCallback(static fn (string $feature): array => [
            'feature' => $feature,
            'label' => $feature,
            'available' => true,
            'installed' => true,
            'enabled' => true,
            'version' => null,
            'package' => '',
            'acl_resource' => null,
            'acl_allowed' => null,
            'modules' => [],
        ]);
        return $features;
    }

    /**
     * @param \ReflectionClass<ToolInterface> $reflection
     * @return \ReflectionProperty|null
     */
    private function contentAclProperty(\ReflectionClass $reflection): ?\ReflectionProperty
    {
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty('contentAcl')) {
                $property = $class->getProperty('contentAcl');
                if ($property->getDeclaringClass()->getName() === $class->getName()) {
                    return $property;
                }
            }
        }
        return null;
    }

    /**
     * Wire names of the content write tools.
     *
     * @return list<string>
     */
    private function writeToolNames(): array
    {
        $names = [];
        foreach ($this->tools as $tool) {
            if ($tool->getWriteMode() === WriteMode::WRITE) {
                $names[] = str_replace('.', '_', $tool->getName());
            }
        }
        return $names;
    }

    /**
     * @param array<string, mixed>|null $result
     * @return list<string>
     */
    private function listedNames(?array $result): array
    {
        $tools = $result['tools'] ?? [];
        $this->assertIsArray($tools);
        return array_values(array_map(static fn (array $tool): string => (string) $tool['name'], $tools));
    }
}
