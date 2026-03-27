<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Typed collection of HTTP routes.
 *
 * Keys are "METHOD /path" format, parsed automatically.
 * Wraps HandlerGroup for dispatch mechanics.
 */
final class RouteGroup implements Executable
{
    private(set) HandlerGroup $inner;

    /** @param array<string, Route> $routes */
    private function __construct(array $routes)
    {
        $handlers = [];
        foreach ($routes as $key => $route) {
            $parsed = self::parseKey($key);
            $config = $route->config;

            if ($parsed !== null) {
                $compiled = RouteConfig::compile($parsed['path'], $parsed['methods']);
                $config = new RouteConfig(
                    $compiled->methods,
                    $compiled->pattern,
                    $compiled->paramNames,
                    $route->config->protocol ?? 'http',
                    $parsed['path'],
                    $route->config->middleware,
                    $route->config->tags,
                    $route->config->priority,
                );
            }

            $handlers[$key] = new Handler($route, $config);
        }
        $this->inner = HandlerGroup::of($handlers)->withMatcher(new RouteMatcher());
    }

    /** @param array<string, Route> $routes */
    public static function of(array $routes): self
    {
        return new self($routes);
    }

    public static function create(): self
    {
        return new self([]);
    }

    public static function fromHandlerGroup(HandlerGroup $inner): self
    {
        $instance = new self([]);
        $instance->inner = $inner->withMatcher(new RouteMatcher());

        return $instance;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->inner)($scope);
    }

    /**
     * Add an HTTP route.
     *
     * @param string|list<string> $method
     */
    public function route(string $path, Scopeable|Executable $handler, string|array $method = 'GET'): self
    {
        $key = self::routeKey($path, $method);
        $config = RouteConfig::compile($path, $method);

        $newInner = $this->inner->add($key, new Handler($handler, $config));

        return self::fromHandlerGroup($newInner);
    }

    public function merge(self $other): self
    {
        $newInner = $this->inner->merge($other->inner);

        return self::fromHandlerGroup($newInner);
    }

    public function mount(string $prefix, self $group): self
    {
        $prefix = rtrim($prefix, '/');
        $mounted = HandlerGroup::create();

        foreach ($group->inner->all() as $key => $handler) {
            if ($handler->config instanceof RouteConfig) {
                $newKey = self::prefixRouteKey($prefix, $key);
                $newConfig = self::prefixRouteConfig($prefix, $handler->config);
                $mounted = $mounted->add($newKey, new Handler($handler->task, $newConfig));
            } else {
                $mounted = $mounted->add($key, $handler);
            }
        }

        $newInner = $this->inner->merge($mounted);

        return self::fromHandlerGroup($newInner);
    }

    public function wrap(Scopeable|Executable ...$middleware): self
    {
        $newInner = $this->inner->wrap(...$middleware);

        return self::fromHandlerGroup($newInner);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->inner->keys();
    }

    /**
     * Get the underlying HandlerGroup for dispatch.
     */
    public function handlers(): HandlerGroup
    {
        return $this->inner;
    }

    /**
     * Get all route handlers.
     *
     * @return array<string, Handler>
     */
    public function routes(): array
    {
        return $this->inner->filterByConfig(RouteConfig::class);
    }

    /**
     * @return array{methods: list<string>, path: string}|null
     */
    private static function parseKey(string $key): ?array
    {
        if (preg_match('#^([A-Z,]+)\s+(/\S*)$#', $key, $m)) {
            return [
                'methods' => explode(',', $m[1]),
                'path' => $m[2],
            ];
        }

        return null;
    }

    /**
     * Build route key from path and method(s).
     *
     * @param string|list<string> $method
     */
    private static function routeKey(string $path, string|array $method): string
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_map(strtoupper(...), $methods);

        return implode(',', $methods) . ' ' . $path;
    }

    private static function prefixRouteKey(string $prefix, string $key): string
    {
        $parsed = self::parseKey($key);

        if ($parsed !== null) {
            return implode(',', $parsed['methods']) . ' ' . $prefix . $parsed['path'];
        }

        return $key;
    }

    private static function prefixRouteConfig(string $prefix, RouteConfig $config): RouteConfig
    {
        $prefixPattern = preg_quote($prefix, '#');
        $innerPattern = substr($config->pattern, 2, -1);
        $newPattern = '#^' . $prefixPattern . $innerPattern . '$#';

        return new RouteConfig(
            $config->methods,
            $newPattern,
            $config->paramNames,
            $config->protocol,
            $prefix . $config->path,
            $config->middleware,
            $config->tags,
            $config->priority,
        );
    }
}
