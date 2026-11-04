<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\AdminEditorUrl;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T-105: the admin editor route per entity type comes from Hyvä's EditorRouteRegistry map, which Hyvä only
 * configures in the adminhtml area, so it is read from the adminhtml DI config (compiled and developer formats).
 */
class AdminEditorUrlTest extends TestCase
{
    private const ROUTES = [
        'email_template' => 'hyva_cms_email/liveview/editor',
        'newsletter_template' => 'hyva_cms_email/liveview/newslettereditor',
    ];

    private const DEVELOPER_CONFIG = [
        AdminEditorUrl::REGISTRY_TYPE => ['arguments' => ['entityTypeRoutes' => self::ROUTES]],
    ];

    public function testDeveloperModeConfigGivesTheRegisteredRoute(): void
    {
        $url = $this->editorUrl(self::DEVELOPER_CONFIG);

        $this->assertSame(
            'hyva_cms_email/liveview/newslettereditor|type=newsletter_template,id=1,_nosecret=1',
            $url->build('newsletter_template', 1)
        );
        $this->assertSame(
            'hyva_cms_email/liveview/editor|type=email_template,id=4,_nosecret=1',
            $url->build('email_template', 4)
        );
    }

    public function testCompiledConfigGivesTheRegisteredRoute(): void
    {
        $url = $this->editorUrl(
            ['arguments' => [AdminEditorUrl::REGISTRY_TYPE => ['entityTypeRoutes' => ['_v_' => self::ROUTES]]]]
        );

        $this->assertSame(
            'hyva_cms_email/liveview/newslettereditor|type=newsletter_template,id=1,_nosecret=1',
            $url->build('newsletter_template', 1)
        );
    }

    public function testAnUnregisteredTypeUsesTheDefaultEditorRoute(): void
    {
        $url = $this->editorUrl(self::DEVELOPER_CONFIG);

        $this->assertSame('liveview/editor/index|type=cms_page,id=13,_nosecret=1', $url->build('cms_page', 13));
        $this->assertSame('liveview/editor/index|type=menu,id=2,_nosecret=1', $url->build('menu', 2));
    }

    public function testTheAdminSessionVariantKeepsTheSecretKey(): void
    {
        $url = $this->editorUrl(self::DEVELOPER_CONFIG);

        $this->assertSame(
            'hyva_cms_email/liveview/editor|type=email_template,id=4',
            $url->build('email_template', 4, true)
        );
    }

    public function testAConfigLoadFailureFallsBackToTheDefaultRouteAndIsLogged(): void
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects($this->once())->method('load')->willThrowException(new RuntimeException('no config'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $url = new AdminEditorUrl($this->backendUrl(), $loader, $logger, $this->cache(), new Json());

        $this->assertSame(
            'liveview/editor/index|type=newsletter_template,id=1,_nosecret=1',
            $url->build('newsletter_template', 1)
        );
        $url->build('email_template', 1);
    }

    public function testAnUnknownConfigShapeFallsBackToTheDefaultRouteAndIsLoggedWithTheShape(): void
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects($this->once())->method('load')->willReturn(
            ['arguments' => [AdminEditorUrl::REGISTRY_TYPE => ['entityTypeRoutes' => ['_vac_' => self::ROUTES]]]]
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('route map'),
            $this->callback(static fn (array $context): bool => str_contains(
                (string) json_encode($context['shape'] ?? null),
                '_vac_'
            ))
        );
        $cache = $this->cache();
        $cache->expects($this->never())->method('save');
        $url = new AdminEditorUrl($this->backendUrl(), $loader, $logger, $cache, new Json());

        $this->assertSame(
            'liveview/editor/index|type=email_template,id=1,_nosecret=1',
            $url->build('email_template', 1)
        );
        $url->build('newsletter_template', 1);
    }

    public function testAResolvedRouteMapIsCachedAndReusedWithoutTheAdminhtmlConfig(): void
    {
        /** @var array<string, string> $stored */
        $stored = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $id) use (&$stored): string|false {
                return $stored[$id] ?? false;
            }
        );
        $cache->expects($this->once())->method('save')->willReturnCallback(
            static function (string $data, string $id) use (&$stored): bool {
                $stored[$id] = $data;
                return true;
            }
        );
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->expects($this->once())->method('load')->willReturn(self::DEVELOPER_CONFIG);
        $logger = $this->createMock(LoggerInterface::class);
        $first = new AdminEditorUrl($this->backendUrl(), $loader, $logger, $cache, new Json());
        $first->build('email_template', 1);
        $first->build('newsletter_template', 1);

        $second = new AdminEditorUrl($this->backendUrl(), $loader, $logger, $cache, new Json());

        $this->assertSame(
            'hyva_cms_email/liveview/newslettereditor|type=newsletter_template,id=1,_nosecret=1',
            $second->build('newsletter_template', 1)
        );
    }

    public function testInvalidRouteValuesAreIgnored(): void
    {
        $url = $this->editorUrl([AdminEditorUrl::REGISTRY_TYPE => ['arguments' => ['entityTypeRoutes' => [
            'email_template' => ['instance' => 'Foo'],
            'newsletter_template' => '',
        ]]]]);

        $this->assertSame(
            'liveview/editor/index|type=email_template,id=1,_nosecret=1',
            $url->build('email_template', 1)
        );
        $this->assertSame(
            'liveview/editor/index|type=newsletter_template,id=1,_nosecret=1',
            $url->build('newsletter_template', 1)
        );
    }

    /**
     * Editor URL builder over the given adminhtml DI config.
     *
     * @param array<mixed> $config
     * @return AdminEditorUrl
     */
    private function editorUrl(array $config): AdminEditorUrl
    {
        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('load')->with('adminhtml')->willReturn($config);
        return new AdminEditorUrl(
            $this->backendUrl(),
            $loader,
            $this->createMock(LoggerInterface::class),
            $this->cache(),
            new Json()
        );
    }

    /**
     * Config cache that holds nothing yet.
     *
     * @return CacheInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        return $cache;
    }

    /**
     * Backend URL builder that renders "route|key=value,...".
     *
     * @return BackendUrl
     */
    private function backendUrl(): BackendUrl
    {
        $url = $this->createMock(BackendUrl::class);
        $url->method('getUrl')->willReturnCallback(static function (string $route, array $params): string {
            $pairs = [];
            foreach ($params as $key => $value) {
                $pairs[] = $key . '=' . (is_bool($value) ? (int) $value : $value);
            }
            return $route . '|' . implode(',', $pairs);
        });
        return $url;
    }
}
