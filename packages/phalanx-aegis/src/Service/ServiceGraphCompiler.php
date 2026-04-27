<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Exception\CyclicDependencyException;
use Phalanx\Exception\InvalidServiceConfigurationException;
use Phalanx\Middleware\ConditionalTransformationMiddleware;
use Phalanx\Support\ClosureInspector;

final class ServiceGraphCompiler
{
    /**
     * @param list<\Phalanx\Middleware\ServiceTransformationMiddleware> $middleware
     * @param array<string, mixed> $context
     */
    public function compile(
        ServiceCatalog $catalog,
        array $middleware,
        array $context,
    ): ServiceGraph {
        $configs = $catalog->resolveConfigs($context);

        $definitions = $catalog->definitions();

        foreach ($definitions as $type => $def) {
            $definitions[$type] = $this->applyMiddleware($def, $middleware);
        }

        $aliases = $catalog->aliases();
        $configTypes = $catalog->configs();

        foreach ($definitions as $type => $def) {
            if ($def->dependencies === [] && $def->factory !== null) {
                $inferred = array_filter(
                    ClosureInspector::classParameters($def->factory),
                    static fn(string $t): bool => isset($definitions[$aliases[$t] ?? $t]) || isset($configTypes[$t]),
                );

                if ($inferred !== []) {
                    $definitions[$type] = $def->withDependencies(...array_values($inferred));
                }
            }
        }

        $this->validateDependencies($definitions, $aliases, $configTypes);

        $this->detectCycles($definitions, $aliases);

        $this->validateSingletonScoping($definitions, $aliases);

        $services = [];

        foreach ($definitions as $type => $def) {
            $depOrder = $this->resolveDependencyOrder($def, $definitions, $aliases);

            $services[$type] = new CompiledService(
                $def->type,
                $depOrder,
                $def->factory ?? $this->defaultFactory($def->type),
                $def->singleton,
                $def->lazy,
                $def->lifecycle,
            );
        }

        return new ServiceGraph($services, $catalog->aliases(), $configs);
    }

    /** @param list<\Phalanx\Middleware\ServiceTransformationMiddleware> $middleware */
    private function applyMiddleware(ServiceDefinition $def, array $middleware): ServiceDefinition
    {
        foreach ($middleware as $mw) {
            if ($mw instanceof ConditionalTransformationMiddleware && !$mw->applies($def)) {
                continue;
            }

            $def = $mw($def);
        }

        return $def;
    }

    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     * @param array<string, \Closure> $configs
     */
    private function validateDependencies(array $definitions, array $aliases, array $configs = []): void
    {
        foreach ($definitions as $type => $def) {
            foreach ($def->dependencies as $dep) {
                $resolved = $aliases[$dep] ?? $dep;

                if (!isset($definitions[$resolved]) && !isset($configs[$dep])) {
                    throw InvalidServiceConfigurationException::missingDependency($type, $dep);
                }
            }
        }
    }

    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     */
    private function detectCycles(array $definitions, array $aliases): void
    {
        $visited = [];
        $stack = [];

        foreach (array_keys($definitions) as $type) {
            if (!isset($visited[$type])) {
                $this->dfs($type, $definitions, $aliases, $visited, $stack);
            }
        }
    }

    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     * @param array<string, bool> $visited
     * @param array<string, bool> $stack
     */
    private function dfs(
        string $type,
        array $definitions,
        array $aliases,
        array &$visited,
        array &$stack,
    ): void {
        $visited[$type] = true;
        $stack[$type] = true;

        $def = $definitions[$type] ?? null;

        if ($def === null) {
            return;
        }

        foreach ($def->dependencies as $dep) {
            $resolved = $aliases[$dep] ?? $dep;

            if (!isset($visited[$resolved])) {
                $this->dfs($resolved, $definitions, $aliases, $visited, $stack);
            } elseif (isset($stack[$resolved])) {
                $cycle = array_keys($stack);
                $cycle[] = $resolved;

                throw new CyclicDependencyException($cycle);
            }
        }

        unset($stack[$type]);
    }

    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     */
    private function validateSingletonScoping(array $definitions, array $aliases): void
    {
        foreach ($definitions as $type => $def) {
            if (!$def->singleton) {
                continue;
            }

            foreach ($def->dependencies as $dep) {
                $resolved = $aliases[$dep] ?? $dep;
                $depDef = $definitions[$resolved] ?? null;

                if ($depDef !== null && !$depDef->singleton) {
                    throw InvalidServiceConfigurationException::singletonDependsOnScoped(
                        $type,
                        $dep,
                    );
                }
            }
        }
    }

    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     * @return list<string>
     */
    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     * @return list<string>
     */
    private function resolveDependencyOrder(
        ServiceDefinition $def,
        array $definitions,
        array $aliases,
    ): array {
        $order = [];
        $seen = [];

        foreach ($def->dependencies as $dep) {
            self::resolveOrder($dep, $definitions, $aliases, $seen, $order);
        }

        return $order;
    }

    /**
     * @param array<string, ServiceDefinition> $definitions
     * @param array<string, string> $aliases
     * @param array<string, true> $seen
     * @param list<string> $order
     */
    private static function resolveOrder(
        string $type,
        array $definitions,
        array $aliases,
        array &$seen,
        array &$order,
    ): void {
        if (isset($seen[$type])) {
            return;
        }

        $seen[$type] = true;
        $resolved = $aliases[$type] ?? $type;
        $depDef = $definitions[$resolved] ?? null;

        if ($depDef !== null) {
            foreach ($depDef->dependencies as $dep) {
                self::resolveOrder($dep, $definitions, $aliases, $seen, $order);
            }
        }

        $order[] = $type;
    }

    private function defaultFactory(string $type): \Closure
    {
        return static fn(...$args): object => new $type(...$args);
    }
}
