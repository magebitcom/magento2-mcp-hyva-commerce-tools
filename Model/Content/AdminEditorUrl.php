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
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Throwable;

/**
 * Admin Liveview editor URL of a Hyvä entity, on the route Hyvä's admin grids link to.
 *
 * Hyvä keeps the route per entity type in EditorRouteRegistry (email and newsletter templates, attributes have their
 * own editor controllers), but fills it only in etc/adminhtml/di.xml, so the registry is empty in the area MCP calls
 * run in. The same map is therefore read from the adminhtml DI config; a type without an entry uses Hyvä's default
 * editor route. The adminhtml DI config is large, so the resolved map is kept in the config cache, next to Magento's
 * own cached DI config, and the config is read only when a link is built and the cache has no map.
 */
class AdminEditorUrl
{
    public const REGISTRY_TYPE = EditorRouteRegistry::class;
    public const DEFAULT_ROUTE = 'liveview/editor/index';

    public const CACHE_ID = 'magebit_mcp_hyva_editor_routes';

    private const ROUTES_ARGUMENT = 'entityTypeRoutes';

    /**
     * @var array<string, string>|null
     */
    private ?array $routes = null;

    /**
     * @param BackendUrl $backendUrl
     * @param ConfigLoaderInterface $configLoader
     * @param LoggerInterface $logger
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly BackendUrl $backendUrl,
        private readonly ConfigLoaderInterface $configLoader,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
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
     * Entity type => route path, resolved once per instance (a shared instance: once per request).
     *
     * @return array<string, string>
     */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $this->routes = $this->cachedRoutes();
        if ($this->routes !== null) {
            return $this->routes;
        }
        $this->routes = $this->routesFromDiConfig();
        $serialized = $this->routes !== [] ? $this->serializer->serialize($this->routes) : false;
        if (is_string($serialized)) {
            $this->cache->save($serialized, self::CACHE_ID);
        }
        return $this->routes;
    }

    /**
     * Route map from the config cache; null when there is no readable entry.
     *
     * @return array<string, string>|null
     */
    private function cachedRoutes(): ?array
    {
        $cached = $this->cache->load(self::CACHE_ID);
        if (!is_string($cached) || $cached === '') {
            return null;
        }
        try {
            $routes = $this->validRoutes($this->serializer->unserialize($cached));
        } catch (Throwable) {
            return null;
        }
        return $routes !== [] ? $routes : null;
    }

    /**
     * Entity type => route path from the adminhtml DI config; empty (and logged) when it cannot be found.
     *
     * @return array<string, string>
     */
    private function routesFromDiConfig(): array
    {
        try {
            $config = $this->configLoader->load(Area::AREA_ADMINHTML);
        } catch (Throwable $e) {
            $this->logger->error('Hyvä editor routes could not be read.', ['exception' => $e]);
            return [];
        }
        // Compiled DI metadata keeps arguments under "arguments" and wraps plain values in "_v_"; the developer
        // mode reader keeps them under the type's own "arguments".
        $map = $config['arguments'][self::REGISTRY_TYPE][self::ROUTES_ARGUMENT]['_v_']
            ?? $config[self::REGISTRY_TYPE]['arguments'][self::ROUTES_ARGUMENT]
            ?? null;
        if (!is_array($map)) {
            $this->logger->warning(
                'Hyvä editor route map not found in the adminhtml DI config; editor links use '
                    . self::DEFAULT_ROUTE . '.',
                ['shape' => $this->describeShape($config)]
            );
            return [];
        }
        return $this->validRoutes($map);
    }

    /**
     * Keys seen where the route map was expected, for the log.
     *
     * @param array $config
     * @return array
     * @phpstan-param array<mixed> $config
     * @phpstan-return array<string, mixed>
     */
    private function describeShape(array $config): array
    {
        $arguments = is_array($config['arguments'] ?? null) ? $config['arguments'] : [];
        $compiled = $arguments[self::REGISTRY_TYPE] ?? null;
        $developer = $config[self::REGISTRY_TYPE] ?? null;
        $keys = static fn (mixed $value): ?array => is_array($value) ? array_keys($value) : null;
        return [
            'compiled' => $keys($compiled),
            'compiled_argument' => is_array($compiled) ? $keys($compiled[self::ROUTES_ARGUMENT] ?? null) : null,
            'developer' => $keys($developer),
            'developer_arguments' => is_array($developer) ? $keys($developer['arguments'] ?? null) : null,
        ];
    }

    /**
     * Only non-empty string routes of the map.
     *
     * @param mixed $map
     * @return array<string, string>
     */
    private function validRoutes(mixed $map): array
    {
        $routes = [];
        foreach (is_array($map) ? $map : [] as $type => $route) {
            if (is_string($route) && $route !== '') {
                $routes[(string) $type] = $route;
            }
        }
        return $routes;
    }
}
