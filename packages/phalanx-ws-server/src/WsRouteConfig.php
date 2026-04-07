<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Http\RouteConfig;

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
        string $pattern = '',
        array $paramNames = [],
        string $path = '',
        array $middleware = [],
        array $tags = [],
        int $priority = 0,
    ) {
        parent::__construct(
            methods: $methods,
            pattern: $pattern,
            paramNames: $paramNames,
            protocol: 'ws',
            path: $path,
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
            pattern: $compiled->pattern,
            paramNames: $compiled->paramNames,
            path: $compiled->path,
            middleware: $compiled->middleware,
            tags: $compiled->tags,
            priority: $compiled->priority,
        );
    }
}
