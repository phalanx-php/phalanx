<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;

final class ServiceCatalog implements Services
{
    /** @var array<string, string> interface => concrete */
    private array $aliases = [];

    /** @var array<string, Closure> type => fromContext closure */
    private array $configs = [];

    /** @var array<string, ServiceDefinition> */
    private array $definitions = [];

    public function config(string $type, Closure $fromContext): void
    {
        $this->configs[$type] = $fromContext;
    }

    /** @param class-string $type */
    public function singleton(string $type): ServiceConfig
    {
        $this->definitions[$type] = new ServiceDefinition(
            type: $type,
            singleton: true,
            lazy: true,
        );

        return new ServiceConfigBuilder($this, $type);
    }

    /** @param class-string $type */
    public function scoped(string $type): ServiceConfig
    {
        $this->definitions[$type] = new ServiceDefinition(
            type: $type,
            singleton: false,
            lazy: true,
        );

        return new ServiceConfigBuilder($this, $type);
    }

    /** @param class-string $type */
    public function eager(string $type): ServiceConfig
    {
        $this->definitions[$type] = new ServiceDefinition(
            type: $type,
            singleton: true,
            lazy: false,
        );

        return new ServiceConfigBuilder($this, $type);
    }

    public function alias(string $interface, string $concrete): void
    {
        $this->aliases[$interface] = $concrete;
    }

    public function updateDefinition(string $type, ServiceDefinition $definition): void
    {
        $this->definitions[$type] = $definition;
    }

    public function getDefinition(string $type): ?ServiceDefinition
    {
        return $this->definitions[$type] ?? null;
    }

    /** @return array<string, ServiceDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return array<string, string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    /** @return array<string, Closure> */
    public function configs(): array
    {
        return $this->configs;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, object>
     */
    public function resolveConfigs(array $context): array
    {
        $resolved = [];

        foreach ($this->configs as $type => $fromContext) {
            $resolved[$type] = $fromContext($context);
        }

        return $resolved;
    }
}
