<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Closure;
use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Http\Contract\HasValidators;
use Phalanx\Http\Contract\Header;
use Phalanx\Http\Contract\InputHydrator;
use Phalanx\Http\Contract\RequiresHeaders;
use Phalanx\Http\Contract\RouteParamValidator;
use Phalanx\Http\Contract\RouteValidator;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Typed collection of HTTP routes.
 *
 * Keys are "METHOD /path" format and parsed automatically. Values are
 * class-strings of Scopeable or Executable handler classes; the framework
 * constructs them at dispatch time via HandlerResolver with constructor
 * injection from the service container.
 *
 * Path placeholders may use named pattern aliases registered via
 * `withPatterns(['int' => '\d+', ...])`. A default set ships built-in
 * (uuid, int, slug, year, month, day, date, any). Users may add their own.
 *
 * Patterns are applied at route-COMPILE time. Routes added via `of()` use
 * whatever patterns are active at construction (defaults). To use custom
 * patterns, chain `withPatterns([...])` before passing the group to
 * composition methods like `merge()`, `mount()`, or `wrap()`.
 * Patterns added via `withPatterns()` only affect routes added afterwards.
 */
final class RouteGroup implements Executable
{
    /** @var array<string, string> */
    public const array DEFAULT_PATTERNS = [
        'int' => '\d+',
        'uuid' => '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}',
        'slug' => '[a-z0-9-]+',
        'year' => '\d{4}',
        'month' => '\d{2}',
        'day' => '\d{2}',
        'date' => '\d{4}-\d{2}-\d{2}',
        'any' => '.+',
    ];

    private(set) HandlerGroup $inner;

    /** @var array<string, string> */
    private array $patterns = self::DEFAULT_PATTERNS;

    /** @var array<string, RouteParamValidator> */
    private array $paramValidators = [];

    /**
     * @param array<string, class-string<Scopeable|Executable>> $routes
     */
    private function __construct(array $routes)
    {
        $handlers = [];
        foreach ($routes as $key => $handlerClass) {
            $parsed = self::parseKey($key);

            if ($parsed === null) {
                continue;
            }

            $config = RouteConfig::compile($parsed['path'], $parsed['methods'], patterns: $this->patterns);
            $handlers[$key] = new Handler($handlerClass, $config);
        }
        $this->inner = HandlerGroup::of($handlers)
            ->withMatcher(new RouteMatcher())
            ->withInvoker(self::httpInvoker());
    }

    /** @param array<string, class-string<Scopeable|Executable>> $routes */
    public static function of(array $routes): self
    {
        return new self($routes);
    }

    /**
     * Wrap a raw HandlerGroup in a RouteGroup, applying HTTP-specific matcher
     * and invoker. Used by RouteLoader when a route file returns a HandlerGroup
     * rather than a RouteGroup directly.
     *
     * @internal
     */
    public static function fromHandlerGroup(HandlerGroup $inner): self
    {
        $instance = new self([]);
        $instance->inner = $inner
            ->withMatcher(new RouteMatcher())
            ->withInvoker(self::httpInvoker());

        return $instance;
    }

    /**
     * HTTP-specific invoker. When the dispatch scope is a RequestScope, runs
     * (in order): header checks, input hydration, then business validators
     * (validators receive the already-typed DTO so they operate on coerced
     * values). For non-HTTP scopes (e.g. tests using a raw ExecutionScope)
     * it falls through to direct invocation.
     *
     * Validator errors are collected from all validators before throwing so
     * callers see the full error set in one response.
     *
     * @return Closure(Scopeable|Executable, ExecutionScope): mixed
     */
    private static function httpInvoker(): Closure
    {
        return static function (Scopeable|Executable $instance, ExecutionScope $scope): mixed {
            if (!$scope instanceof RequestScope) {
                return $instance($scope);
            }

            if ($instance instanceof RequiresHeaders) {
                self::enforceRequiredHeaders($instance->requiredHeaders, $scope);
            }

            // Run param validators before hydration -- they operate on raw
            // route param strings, not the hydrated DTO.
            if ($scope->config->paramValidators !== []) {
                self::enforceParamValidators($scope->config->paramValidators, $scope);
            }

            // Hydrate before route validators so they receive the typed, coerced DTO.
            $args = InputHydrator::resolve($instance, $scope);

            // $args is either [], [$scope], or [$scope, $input]. Extract $input for validators.
            $input = count($args) >= 2 ? $args[1] : null;

            if ($instance instanceof HasValidators && $instance->validators !== []) {
                /** @var HandlerResolver $resolver */
                $resolver = $scope->service(HandlerResolver::class);
                $errors = [];
                foreach ($instance->validators as $validatorClass) {
                    /** @var RouteValidator $validator */
                    $validator = $resolver->resolve($validatorClass, $scope);
                    $fieldErrors = $validator->validate($input, $scope);
                    foreach ($fieldErrors as $field => $messages) {
                        $errors[$field] = array_merge($errors[$field] ?? [], $messages);
                    }
                }
                if ($errors !== []) {
                    throw new ValidationException($errors);
                }
            }

            return $instance(...$args);
        };
    }

    /**
     * @param array<string, RouteParamValidator> $validators
     */
    private static function enforceParamValidators(array $validators, RequestScope $scope): void
    {
        $errors = [];

        foreach ($validators as $paramName => $validator) {
            $value = $scope->params->get($paramName);

            if ($value === null) {
                continue;
            }

            $error = $validator->validate($paramName, $value);
            if ($error !== null) {
                $errors[$paramName][] = $error;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param list<Header> $required
     */
    private static function enforceRequiredHeaders(array $required, RequestScope $scope): void
    {
        $errors = [];

        foreach ($required as $header) {
            $value = $scope->header($header->name);

            if ($value === '') {
                if ($header->required) {
                    $errors[$header->name][] = 'This header is required';
                }
                continue;
            }

            if ($header->pattern !== null && preg_match('#^' . $header->pattern . '$#', $value) !== 1) {
                $errors[$header->name][] = 'Header value does not match required pattern';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->inner)($scope);
    }

    /**
     * Register named pattern aliases for path placeholders.
     *
     * Accepts a mix of string patterns (fed to FastRoute at compile time) and
     * RouteParamValidator instances keyed by PARAM NAME. Validators are
     * attached to every route in the group whose compiled paramNames include
     * the given key. Validators that return a non-null toPattern() are used
     * as both the compile-time regex constraint AND the imperative validator.
     *
     * String pattern aliases (e.g. 'int' => '\d+') continue to work as
     * before and apply to routes added AFTER this call only.
     *
     * @param array<string, string|RouteParamValidator> $patterns
     */
    public function withPatterns(array $patterns): self
    {
        $newStringPatterns = [];
        $newParamValidators = [];

        foreach ($patterns as $alias => $value) {
            if (is_string($value)) {
                $newStringPatterns[$alias] = $value;
            } else {
                $newParamValidators[$alias] = $value;
                $regex = $value->toPattern();
                if ($regex !== null) {
                    $newStringPatterns[$alias] = $regex;
                }
            }
        }

        // Attach imperative validators to existing routes whose param names
        // match. Re-build the inner HandlerGroup preserving all state except
        // the updated handler entries.
        $inner = $this->inner;
        if ($newParamValidators !== []) {
            foreach ($inner->all() as $key => $handler) {
                if (!$handler->config instanceof RouteConfig) {
                    continue;
                }
                $matchingValidators = array_intersect_key(
                    $newParamValidators,
                    array_flip($handler->config->paramNames),
                );
                if ($matchingValidators !== []) {
                    $newConfig = $handler->config->withParamValidators(
                        [...$handler->config->paramValidators, ...$matchingValidators]
                    );
                    $inner = $inner->add($key, new Handler($handler->task, $newConfig));
                }
            }
        }

        $clone = self::fromHandlerGroup($inner);
        $clone->patterns = [...$this->patterns, ...$newStringPatterns];
        $clone->paramValidators = [...$this->paramValidators, ...$newParamValidators];

        return $clone;
    }

    public function merge(self $other): self
    {
        $newInner = $this->inner->merge($other->inner);

        $clone = self::fromHandlerGroup($newInner);
        $clone->patterns = [...$this->patterns, ...$other->patterns];
        $clone->paramValidators = [...$this->paramValidators, ...$other->paramValidators];

        return $clone;
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

        $clone = self::fromHandlerGroup($newInner);
        $clone->patterns = [...$this->patterns, ...$group->patterns];
        $clone->paramValidators = [...$this->paramValidators, ...$group->paramValidators];

        return $clone;
    }

    /**
     * Wrap all routes in this group with middleware (outermost layer).
     *
     * @param class-string ...$middleware
     */
    public function wrap(string ...$middleware): self
    {
        $newInner = $this->inner->wrap(...$middleware);

        $clone = self::fromHandlerGroup($newInner);
        $clone->patterns = $this->patterns;
        $clone->paramValidators = $this->paramValidators;

        return $clone;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->inner->keys();
    }

    public function handlers(): HandlerGroup
    {
        return $this->inner;
    }

    /** @return array<string, Handler> */
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
        // Pattern format is '#^/path$#'. Strip the '#^' delimiter+anchor (2
        // chars) from the start and the trailing '#' delimiter (1 char) from
        // the end, leaving '/path$'. Then prepend a fresh '#^/prefix' and
        // close with '#' to produce the final '#^/prefix/path$#'.
        $innerPattern = substr($config->pattern, 2, -1);
        $newPattern = '#^' . $prefixPattern . $innerPattern . '#';

        return new RouteConfig(
            $config->methods,
            $newPattern,
            $config->paramNames,
            $config->protocol,
            $prefix . $config->path,
            $config->middleware,
            $config->tags,
            $config->priority,
            $config->paramValidators,
        );
    }
}
