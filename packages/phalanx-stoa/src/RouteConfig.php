<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Handler\HandlerConfig;
use Phalanx\Stoa\Contract\RouteParamValidator;

/**
 * HTTP route configuration with path matching and middleware.
 *
 * RouteConfig stores Stoa route metadata and the FastRoute-compatible path
 * generated from it. Runtime matching belongs exclusively to FastRoute.
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
        private(set) array $methods = ['GET'],
        private(set) string $path = '',
        private(set) string $fastRoutePath = '',
        private(set) array $paramNames = [],
        array $middleware = [],
        array $tags = [],
        int $priority = 0,
        private(set) array $paramValidators = [],
    ) {
        parent::__construct($tags, $priority, $middleware);
    }

    /**
     * Compile Stoa's route syntax into FastRoute's route syntax.
     *
     * /users/{id}        -> /users/{id}
     * /users/{id:int}    -> /users/{id:\d+}        (named alias)
     * /users/{id:\d+}    -> /users/{id:\d+}        (literal regex)
     *
     * @param string|list<string> $method
     * @param array<string, string> $patterns Named pattern aliases
     */
    public static function compile(
        string $path,
        string|array $method = 'GET',
        array $patterns = [],
    ): self {
        $methods = is_array($method) ? $method : [$method];
        $methods = array_values(array_map(strtoupper(...), $methods));
        [$fastRoutePath, $paramNames] = self::compileFastRoutePath($path, $patterns);

        return new self(
            methods: $methods,
            path: $path,
            fastRoutePath: $fastRoutePath,
            paramNames: $paramNames,
        );
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

    /**
     * @param array<string, string> $patterns
     * @return array{string, list<string>}
     */
    private static function compileFastRoutePath(string $path, array $patterns): array
    {
        $params = [];
        $fastRoutePath = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#',
            static function (array $match) use (&$params, $patterns): string {
                $name = $match[1];
                $constraint = $match[2] ?? null;
                $params[] = $name;

                $pattern = $constraint === null
                    ? ($patterns[$name] ?? null)
                    : ($patterns[$constraint] ?? $constraint);

                if ($pattern === null || $pattern === '') {
                    return "{{$name}}";
                }

                return "{{$name}:{$pattern}}";
            },
            $path,
        );

        return [$fastRoutePath ?? $path, array_values(array_unique($params))];
    }
}
