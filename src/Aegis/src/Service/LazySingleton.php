<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;

/**
 * Application-wide singleton cache + onShutdown chain.
 * One instance lives in Application; shared across all scopes.
 */
class LazySingleton
{
    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var list<class-string> */
    private array $creationOrder = [];

    public function __construct(private readonly ServiceGraph $graph)
    {
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @param Closure(): T $build
     * @return T
     */
    public function get(string $type, Closure $build): object
    {
        $resolved = $this->graph->alias($type);
        if (!isset($this->instances[$resolved])) {
            /** @var T $instance */
            $instance = $build();
            // @dev-cleanup-ignore — guard against re-entrant factory chains that recurse through service()
            if (!isset($this->instances[$resolved])) {
                $this->instances[$resolved] = $instance;
                $this->creationOrder[] = $resolved;
            }
        }
        /** @var T $cached */
        $cached = $this->instances[$resolved];
        return $cached;
    }

    public function shutdown(): void
    {
        foreach (array_reverse($this->creationOrder) as $type) {
            $instance = $this->instances[$type] ?? null;
            if ($instance === null) {
                continue;
            }
            $config = $this->graph->configs[$type] ?? null;
            if ($config === null) {
                continue;
            }
            foreach ($config->onShutdownHooks as $hook) {
                try {
                    $hook($instance);
                } catch (\Throwable) {
                }
            }
        }
        $this->instances = [];
        $this->creationOrder = [];
    }

    /** @param Closure(class-string): object $factory */
    public function startupEager(Closure $factory): void
    {
        foreach ($this->graph->configs as $type => $config) {
            if ($config->lifetime !== ServiceLifetime::Singleton || $config->lazy) {
                continue;
            }
            $instance = $this->get($type, static fn() => $factory($type));
            foreach ($config->onStartupHooks as $hook) {
                $hook($instance);
            }
        }
    }
}
