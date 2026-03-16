<?php

declare(strict_types=1);

namespace Convoy\Service;

use Convoy\Exception\ServiceNotFoundException;

final readonly class ServiceGraph
{
    public function __construct(
        /** @var array<string, CompiledService> */
        public array $services,
        /** @var array<string, string> */
        public array $aliases,
        /** @var array<string, mixed> */
        public array $configs,
    ) {
    }

    public function resolve(string $type): CompiledService
    {
        $resolved = $this->aliases[$type] ?? $type;

        if (!isset($this->services[$resolved])) {
            throw new ServiceNotFoundException($type);
        }

        return $this->services[$resolved];
    }

    public function has(string $type): bool
    {
        $resolved = $this->aliases[$type] ?? $type;
        return isset($this->services[$resolved]);
    }

    public function config(string $type): mixed
    {
        if (!isset($this->configs[$type])) {
            throw new ServiceNotFoundException("Config: $type");
        }

        return $this->configs[$type];
    }

    public function hasConfig(string $type): bool
    {
        return isset($this->configs[$type]);
    }

    /** @return list<CompiledService> */
    public function startupServices(): array
    {
        return array_values(
            array_filter(
                $this->services,
                fn(CompiledService $s): bool => $s->lifecycle->hasStartup(),
            )
        );
    }

    /** @return list<CompiledService> */
    public function shutdownServices(): array
    {
        return array_values(
            array_filter(
                $this->services,
                fn(CompiledService $s): bool => $s->lifecycle->hasShutdown(),
            )
        );
    }

    /** @return list<CompiledService> */
    public function eagerSingletons(): array
    {
        return array_values(
            array_filter(
                $this->services,
                fn(CompiledService $s): bool => $s->singleton && !$s->lazy,
            )
        );
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->services);
    }
}
