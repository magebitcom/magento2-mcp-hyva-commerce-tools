<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Config\EditorRouteRegistry;
use Magebit\Mcp\Api\LoggerInterface;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Area;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Throwable;

/**
 * Admin Liveview editor URL of a Hyvä entity, on the route Hyvä's admin grids link to.
 *
 * Hyvä keeps the route per entity type in EditorRouteRegistry (email and newsletter templates, attributes have their
 * own editor controllers), but fills it only in etc/adminhtml/di.xml, so the registry is empty in the area MCP calls
 * run in. The same map is therefore read from the adminhtml DI config; a type without an entry uses Hyvä's default
 * editor route.
 */
class AdminEditorUrl
{
    public const REGISTRY_TYPE = EditorRouteRegistry::class;
    public const DEFAULT_ROUTE = 'liveview/editor/index';

    private const ROUTES_ARGUMENT = 'entityTypeRoutes';

    /**
     * @var array<string, string>|null
     */
    private ?array $routes = null;

    /**
     * @param BackendUrl $backendUrl
     * @param ConfigLoaderInterface $configLoader
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly BackendUrl $backendUrl,
        private readonly ConfigLoaderInterface $configLoader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Admin editor URL of an entity.
     *
     * Without $withSecretKey the URL has no admin secret key: a key minted outside the admin's browser session (an
     * MCP token request) never matches it, so with "Add Secret Key to URLs" on such a link opens the dashboard.
     * Pass true only when the URL is rendered inside an admin page.
     *
     * @param string $type Hyvä entity type (cms_page, cms_block, menu, email_template, newsletter_template, ...).
     * @param int $id
     * @param bool $withSecretKey
     * @return string
     */
    public function build(string $type, int $id, bool $withSecretKey = false): string
    {
        $params = ['type' => $type, 'id' => $id];
        if (!$withSecretKey) {
            $params['_nosecret'] = true;
        }
        return $this->backendUrl->getUrl($this->routePath($type), $params);
    }

    /**
     * Editor route and params of an entity, for a link built later inside an admin page.
     *
     * Cached admin data (dashboard widgets) keeps this instead of a URL: a URL carries the secret key of the session
     * that built it, which a later session does not accept (T-118).
     *
     * @param string $type Hyvä entity type.
     * @param int $id
     * @return array
     * @phpstan-return array{route: string, params: array{type: string, id: int}}
     */
    public function link(string $type, int $id): array
    {
        return ['route' => $this->routePath($type), 'params' => ['type' => $type, 'id' => $id]];
    }

    /**
     * Route path registered for the type, or the default editor route.
     *
     * @param string $type
     * @return string
     */
    public function routePath(string $type): string
    {
        return $this->routes()[$type] ?? self::DEFAULT_ROUTE;
    }

    /**
     * Entity type => route path from the adminhtml DI config, read once per instance.
     *
     * @return array<string, string>
     */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $this->routes = [];
        try {
            $config = $this->configLoader->load(Area::AREA_ADMINHTML);
        } catch (Throwable $e) {
            $this->logger->error('Hyvä editor routes could not be read.', ['exception' => $e]);
            return $this->routes;
        }
        // Compiled DI metadata keeps arguments under "arguments" and wraps plain values in "_v_"; the developer
        // mode reader keeps them under the type's own "arguments".
        $map = $config['arguments'][self::REGISTRY_TYPE][self::ROUTES_ARGUMENT]['_v_']
            ?? $config[self::REGISTRY_TYPE]['arguments'][self::ROUTES_ARGUMENT]
            ?? [];
        if (!is_array($map)) {
            return $this->routes;
        }
        foreach ($map as $type => $route) {
            if (is_string($route) && $route !== '') {
                $this->routes[(string) $type] = $route;
            }
        }
        return $this->routes;
    }
}
