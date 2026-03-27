<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Support\ErrorHandler;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;

final class LazySingleton
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<string> */
    private array $creationOrder = [];

    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly Trace $trace,
    ) {
    }

    public function get(string $type, ?callable $scopeResolver = null): object
    {
        $resolved = $this->graph->aliases[$type] ?? $type;

        if (isset($this->instances[$resolved])) {
            return $this->instances[$resolved];
        }

        $compiled = $this->graph->resolve($resolved);

        if (!$compiled->singleton) {
            throw new \LogicException("Cannot use SingletonContainer for scoped service: $type");
        }

        $deps = $this->resolveDependencies($compiled, $scopeResolver);

        $instance = $this->createInstance($compiled, $deps);

        $this->instances[$resolved] = $instance;
        $this->creationOrder[] = $resolved;

        return $instance;
    }

    public function has(string $type): bool
    {
        $resolved = $this->graph->aliases[$type] ?? $type;
        return isset($this->instances[$resolved]);
    }

    public function startup(): void
    {
        foreach ($this->graph->eagerSingletons() as $compiled) {
            $this->get($compiled->type);
        }

        $this->trace->log(TraceType::LifecycleStartup, 'startup');

        foreach ($this->graph->startupServices() as $compiled) {
            if (!$compiled->singleton) {
                continue;
            }

            $instance = $this->get($compiled->type);

            if (LazyFactory::isUninitialized($instance)) {
                LazyFactory::initializeIfLazy($instance);
            }

            foreach ($compiled->lifecycle->onStartup as $hook) {
                $hook($instance);
            }
        }

        $this->trace->log(TraceType::LifecycleStartup, 'ready');
    }

    public function shutdown(): void
    {
        $this->trace->log(TraceType::LifecycleShutdown, 'shutdown');

        $reversed = array_reverse($this->creationOrder);

        foreach ($reversed as $type) {
            $compiled = $this->graph->resolve($type);
            $instance = $this->instances[$type] ?? null;

            if ($instance === null) {
                continue;
            }

            if (LazyFactory::isUninitialized($instance)) {
                continue;
            }

            foreach ($compiled->lifecycle->onShutdown as $hook) {
                try {
                    $hook($instance);
                } catch (\Throwable $e) {
                    ErrorHandler::report("Shutdown hook failed for $type: " . $e->getMessage());
                }
            }

            $this->trace->log(TraceType::ServiceDispose, $compiled->shortName());
        }

        $this->instances = [];
        $this->creationOrder = [];
    }

    public function config(string $type): mixed
    {
        return $this->graph->config($type);
    }

    /** @return list<object> */
    private function resolveDependencies(CompiledService $compiled, ?callable $scopeResolver): array
    {
        $deps = [];

        foreach ($compiled->dependencyOrder as $depType) {
            $depCompiled = $this->graph->resolve($depType);

            if ($depCompiled->singleton) {
                $deps[] = $this->get($depType, $scopeResolver);
            } elseif ($scopeResolver !== null) {
                $deps[] = $scopeResolver($depType);
            } else {
                throw new \LogicException(
                    "Cannot resolve scoped dependency '$depType' for singleton '{$compiled->type}' without scope"
                );
            }
        }

        return $deps;
    }

    /** @param list<object> $deps */
    private function createInstance(CompiledService $compiled, array $deps): object
    {
        $factory = $compiled->factory;

        if ($compiled->lazy) {
            return LazyFactory::wrap($compiled->type, static fn() => $factory(...$deps), $this->trace);
        }

        $this->trace->log(TraceType::ServiceInit, $compiled->shortName());

        $instance = $factory(...$deps);

        foreach ($compiled->lifecycle->onInit as $hook) {
            $hook($instance);
        }

        return $instance;
    }
}
