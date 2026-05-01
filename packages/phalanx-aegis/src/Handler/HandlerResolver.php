<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Scope\Scope;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Constructs handler instances by reflecting on their constructors and
 * resolving each parameter from the service container.
 *
 * Lives as an application singleton. The reflection cache accumulates one
 * entry per handler class-string and survives for the lifetime of the
 * application -- handlers are constructed at every request, so the cache
 * pays back immediately on the second hit.
 *
 * Resolution rules:
 *
 *  - Every constructor parameter must be a typed object resolvable from the
 *    service container. Scalar parameters and untyped parameters throw at
 *    cache time (first reflection of the class).
 *  - Nullable parameters whose type is not registered as a service resolve
 *    to `null` instead of throwing.
 *  - Parameters with a default value whose type is not registered fall back
 *    to the declared default.
 *  - Handlers with no constructor (or no parameters) are constructed with
 *    no arguments.
 *  - The container is queried at resolve() time via the caller-supplied
 *    scope -- the resolver does not hold a stale scope reference.
 */
final class HandlerResolver
{
    /** @var array<class-string, list<HandlerResolverParam>> */
    private array $paramCache = [];

    /**
     * @param class-string $class
     * @return list<HandlerResolverParam>
     */
    private static function reflectParams(string $class): array
    {
        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType) {
                throw new HandlerDependencyNotResolvable(
                    $class,
                    $param->getName(),
                    null,
                    'parameter must be a single named class type',
                );
            }

            if ($type->isBuiltin()) {
                throw new HandlerDependencyNotResolvable(
                    $class,
                    $param->getName(),
                    $type->getName(),
                    'scalar/builtin parameters are not allowed -- read scalars from $scope inside __invoke',
                );
            }

            $params[] = new HandlerResolverParam(
                name: $param->getName(),
                type: $type->getName(),
                nullable: $type->allowsNull(),
                hasDefault: $param->isDefaultValueAvailable(),
                default: self::safeDefaultValue($param),
            );
        }

        return $params;
    }

    private static function safeDefaultValue(ReflectionParameter $param): mixed
    {
        if (!$param->isDefaultValueAvailable()) {
            return null;
        }

        return $param->getDefaultValue();
    }

    /**
     * Construct an instance of $handlerClass with constructor parameters
     * resolved from the service container via $scope.
     *
     * Generic over any object class -- this resolver is used for handlers
     * (Scopeable / Executable), middleware, route validators, and any other
     * framework-orchestrated type that needs DI-driven construction.
     *
     * @template T of object
     * @param class-string<T> $handlerClass
     * @return T
     */
    public function resolve(string $handlerClass, Scope $scope): object
    {
        $params = $this->paramCache[$handlerClass] ??= self::reflectParams($handlerClass);

        $args = [];
        foreach ($params as $param) {
            try {
                /** @var class-string $type */
                $type = $param->type;
                $args[] = $scope->service($type);
            } catch (ServiceNotFoundException $e) {
                if ($param->nullable) {
                    $args[] = null;
                    continue;
                }
                if ($param->hasDefault) {
                    $args[] = $param->default;
                    continue;
                }
                throw new HandlerDependencyNotResolvable(
                    $handlerClass,
                    $param->name,
                    $param->type,
                    $e->getMessage(),
                );
            }
        }

        /** @var T */
        return new $handlerClass(...$args);
    }
}
