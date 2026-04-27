<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Handler\HandlerConfig;
use Phalanx\Stoa\Contract\RouteParamValidator;

/**
 * HTTP route configuration with path matching and middleware.
 *
 * Routes are compiled via FastRoute for O(1) dispatch.
 * RouteConfig remains the source of truth for route definition;
 * FastRouteCompiler reads from it to build the dispatch table.
 */
class RouteConfig extends HandlerConfig
{
    /**
     * @param list<string> $methods
     * @param list<string> $paramNames
     * @param list<class-string> $middleware
     * @param list<string> $tags
     * @param array<string, RouteParamValidator> $paramValidators
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
        public private(set) array $paramValidators = [],
    ) {
        parent::__construct($tags, $priority, $middleware);
    }

    /**
     * Compile a path pattern into regex and extract param names.
     *
     * /users/{id}        -> /users/(?P<id>[^/]+)
     * /users/{id:int}    -> /users/(?P<id>\d+)        (named alias)
     * /users/{id:\d+}    -> /users/(?P<id>\d+)        (literal regex)
     *
     * @param string|list<string> $method
     * @param array<string, string> $patterns Named pattern aliases
     */
    public static function compile(
        string $path,
        string|array $method = 'GET',
        string $protocol = 'http',
        array $patterns = [],
    ): self {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_values(array_map(strtoupper(...), $methods));

        $paramNames = [];
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $m) use (&$paramNames, $patterns): string {
                $paramNames[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                $constraint = $patterns[$constraint] ?? $constraint;
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

    /**
     * Attach imperative param validators to this route config.
     *
     * These run after FastRoute match, against the extracted param values.
     * Validators that also provide toPattern() should have had their patterns
     * applied at compile time via RouteGroup::withPatterns().
     *
     * @param array<string, RouteParamValidator> $validators
     */
    public function withParamValidators(array $validators): self
    {
        $clone = clone $this;
        $clone->paramValidators = $validators;
        return $clone;
    }
}
