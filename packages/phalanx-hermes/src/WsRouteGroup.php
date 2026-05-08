<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteMatcher;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Typed collection of WebSocket routes consumed by {@see \Phalanx\Hermes\Server\WsServerUpgrade}.
 *
 * Each route entry is either:
 *  - a class-string of a Scopeable/Executable handler (uses default WsConfig)
 *  - a tuple [class-string, WsConfig] when the route needs custom WS settings
 *
 * Handlers are resolved at upgrade time via HandlerResolver with constructor
 * injection from the service container.
 *
 * @phpstan-type WsRouteEntry class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, WsConfig}
 * @phpstan-type WsRouteMap array<string, WsRouteEntry>
 */
final class WsRouteGroup
{
    private(set) HandlerGroup $inner;

    /** @param WsRouteMap $routes */
    private function __construct(
        array $routes,
        private readonly WsGateway $gateway,
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
                $wsConfig = new WsConfig();
            }

            $compiled = RouteConfig::compile($parsed, 'GET');
            $config = WsRouteConfig::fromCompiled($compiled, $wsConfig);

            $handlers[$key] = new Handler($class, $config);
        }

        $this->inner = HandlerGroup::of($handlers)->withMatcher(new RouteMatcher());
    }

    /** @param WsRouteMap $routes */
    public static function of(
        array $routes,
        ?WsGateway $gateway = null,
    ): self {
        return new self(
            $routes,
            $gateway ?? new WsGateway(),
        );
    }

    public function gateway(): WsGateway
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
