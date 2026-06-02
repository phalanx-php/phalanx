<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Stoa\RouteConfig;

/**
 * RouteConfig variant that carries the WebSocket-specific WsConfig alongside
 * the HTTP route data. This lets WsRouteGroup store everything in a single
 * Handler->config slot rather than threading WsConfig as a separate channel.
 */
final class WsRouteConfig extends RouteConfig
{
    /**
     * @param list<string> $methods
     * @param list<string> $paramNames
     * @param list<class-string> $middleware
     * @param list<string> $tags
     */
    public function __construct(
        public readonly WsConfig $wsConfig,
        array $methods = ['GET'],
        string $path = '',
        string $fastRoutePath = '',
        array $paramNames = [],
        array $middleware = [],
        array $tags = [],
        int $priority = 0,
    ) {
        parent::__construct(
            methods: $methods,
            path: $path,
            fastRoutePath: $fastRoutePath,
            paramNames: $paramNames,
            middleware: $middleware,
            tags: $tags,
            priority: $priority,
        );
    }

    public static function fromCompiled(RouteConfig $compiled, WsConfig $wsConfig): self
    {
        return new self(
            wsConfig: $wsConfig,
            methods: $compiled->methods,
            path: $compiled->path,
            fastRoutePath: $compiled->fastRoutePath,
            paramNames: $compiled->paramNames,
            middleware: $compiled->middleware,
            tags: $compiled->tags,
            priority: $compiled->priority,
        );
    }
}
