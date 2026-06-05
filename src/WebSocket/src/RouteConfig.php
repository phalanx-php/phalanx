<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Http\RouteConfig as HttpRouteConfig;

/**
 * RouteConfig variant that carries the WebSocket-specific Config alongside
 * the HTTP route data. This lets RouteGroup store everything in a single
 * Handler->config slot rather than threading Config as a separate channel.
 */
final class RouteConfig extends HttpRouteConfig
{
    /**
     * @param list<string> $methods
     * @param list<string> $paramNames
     * @param list<class-string> $middleware
     * @param list<string> $tags
     */
    public function __construct(
        public readonly \Phalanx\WebSocket\Config $wsConfig,
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

    public static function fromCompiled(HttpRouteConfig $compiled, \Phalanx\WebSocket\Config $wsConfig): self
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
