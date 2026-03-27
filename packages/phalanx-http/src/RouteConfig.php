<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Handler\HandlerConfig;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * HTTP route configuration with path matching and middleware.
 *
 * Routes are compiled via FastRoute for O(1) dispatch.
 * RouteConfig remains the source of truth for route definition;
 * FastRouteCompiler reads from it to build the dispatch table.
 */
final class RouteConfig extends HandlerConfig
{
    /**
     * @param list<string> $methods
     * @param list<string> $paramNames
     * @param list<Scopeable|Executable> $middleware
     * @param list<string> $tags
     */
    public function __construct(
        public private(set) array $methods = ['GET'],
        public private(set) string $pattern = '',
        public private(set) array $paramNames = [],
        public private(set) string $protocol = 'http',
        public private(set) string $path = '',
        array $middleware = [],
        array $tags = [],
        int $priority = 0,
    ) {
        parent::__construct($tags, $priority, $middleware);
    }

    /**
     * Compile a path pattern into regex and extract param names.
     *
     * /users/{id}        -> /users/(?P<id>[^/]+)
     * /users/{id:\d+}    -> /users/(?P<id>\d+)
     *
     * @param string|list<string> $method
     */
    public static function compile(
        string $path,
        string|array $method = 'GET',
        string $protocol = 'http',
    ): self {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_values(array_map(strtoupper(...), $methods));

        $paramNames = [];
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                return "(?P<{$m[1]}>{$constraint})";
            },
            $path,
        );

        $pattern = '#^' . $pattern . '$#';

        return new self(
            methods: $methods,
            pattern: $pattern,
            paramNames: $paramNames,
            protocol: $protocol,
            path: $path,
        );
    }

    /**
     * Check if this route matches the given method and path.
     *
     * @return array<string, string>|null Params if matched, null otherwise
     */
    public function matches(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        if (!in_array($method, $this->methods, true)) {
            return null;
        }

        if (!preg_match($this->pattern, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            if (isset($matches[$name])) {
                $params[$name] = $matches[$name];
            }
        }

        return $params;
    }

    public function withProtocol(string $protocol): self
    {
        $clone = clone $this;
        $clone->protocol = $protocol;
        return $clone;
    }

    /** @param string|list<string> $method */
    public function withMethod(string|array $method): self
    {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_values(array_map(strtoupper(...), $methods));

        $clone = clone $this;
        $clone->methods = $methods;
        return $clone;
    }

    public function withPath(string $path): self
    {
        $compiled = self::compile($path, $this->methods, $this->protocol);

        $clone = clone $this;
        $clone->path = $path;
        $clone->pattern = $compiled->pattern;
        $clone->paramNames = $compiled->paramNames;
        return $clone;
    }

    #[\Override]
    public function withMiddleware(Scopeable|Executable ...$middleware): static
    {
        $clone = clone $this;
        $clone->middleware = array_values([...$this->middleware, ...$middleware]);
        return $clone;
    }

    #[\Override]
    public function withTags(string ...$tags): static
    {
        $clone = clone $this;
        $clone->tags = array_values([...$this->tags, ...$tags]);
        return $clone;
    }

    #[\Override]
    public function withPriority(int $priority): static
    {
        $clone = clone $this;
        $clone->priority = $priority;
        return $clone;
    }
}
