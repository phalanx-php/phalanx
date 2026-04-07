<?php

declare(strict_types=1);

namespace Phalanx\Handler;

use Phalanx\Scope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

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
 *    service container. Scalar parameters and untyped parameters throw.
 *  - Handlers with no constructor (or no parameters) are constructed with
 *    no arguments.
 *  - The container is asked at resolve() time via the caller-supplied scope
 *    -- the resolver does not hold a stale scope reference.
 */
final class HandlerResolver
{
    /** @var array<class-string, list<string>> */
    private array $paramCache = [];

    /**
     * @template T of Scopeable|Executable
     * @param class-string<T> $handlerClass
     * @return T
     */
    public function resolve(string $handlerClass, Scope $scope): Scopeable|Executable
    {
        $params = $this->paramCache[$handlerClass] ??= self::reflectParams($handlerClass);

        $args = [];
        foreach ($params as $type) {
            try {
                /** @var class-string $type */
                $args[] = $scope->service($type);
            } catch (Throwable $e) {
                throw new HandlerDependencyNotResolvable(
                    $handlerClass,
                    $type,
                    $type,
                    $e->getMessage(),
                );
            }
        }

        /** @var T */
        return new $handlerClass(...$args);
    }

    /**
     * @param class-string $class
     * @return list<string>
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

            $params[] = $type->getName();
        }

        return $params;
    }
}
