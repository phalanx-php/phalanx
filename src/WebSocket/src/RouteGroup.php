<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteGroup as HttpRouteGroup;
use Phalanx\Http\RouteMatcher;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Typed collection of WebSocket routes consumed by {@see \Phalanx\WebSocket\Server\Upgrade}.
 *
 * Each route entry is either:
 *  - a class-string of a Scopeable/Executable handler (uses default Config)
 *  - a tuple [class-string, Config] when the route needs custom settings
 *
 * Handlers are resolved at upgrade time via HandlerResolver with constructor
 * injection from the service container.
 *
 * @phpstan-type WsRouteEntry class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, Config}
 * @phpstan-type WsRouteMap array<string, WsRouteEntry>
 */
final class RouteGroup
{
    private(set) HandlerGroup $inner;

    /** @param WsRouteMap $routes */
    private function __construct(
        array $routes,
        private readonly \Phalanx\WebSocket\Gateway $gateway,
    ) {
        $handlers = [];

        foreach ($routes as $key => $entry) {
            $parsed = self::parseKey($key);

            if ($parsed === null) {
                continue;
            }

            if (is_array($entry)) {
                [$class, $wsConfig] = $entry;
            } else {
                $class = $entry;
                $wsConfig = new \Phalanx\WebSocket\Config();
            }

            $compiled = RouteConfig::compile($parsed, 'GET', HttpRouteGroup::DEFAULT_PATTERNS);
            $config = \Phalanx\WebSocket\RouteConfig::fromCompiled($compiled, $wsConfig);

            $handlers[$key] = new Handler($class, $config);
        }

        $this->inner = HandlerGroup::of($handlers)->withMatcher(new RouteMatcher());
    }

    /** @param WsRouteMap $routes */
    public static function of(
        array $routes,
        \Phalanx\WebSocket\Gateway $gateway,
    ): self {
        return new self(
            $routes,
            $gateway,
        );
    }

    public function gateway(): \Phalanx\WebSocket\Gateway
    {
        return $this->gateway;
    }

    private static function parseKey(string $key): ?string
    {
        if (preg_match('#^WS\s+(/\S*)$#i', $key, $m)) {
            return $m[1];
        }

        if (str_starts_with($key, '/')) {
            return $key;
        }

        return null;
    }
}
