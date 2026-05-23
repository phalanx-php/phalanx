<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Exception\ServiceNotFoundException;
use RuntimeException;

/**
 * Compiled, read-only view of the service registry. Built once by ServiceCatalog::compile()
 * and shared across all scopes for the application lifetime.
 */
final class ServiceGraph
{
    /**
     * @param array<class-string, CompiledServiceConfig> $configs
     * @param array<class-string, mixed> $contextConfigs
     * @param array<class-string, class-string> $aliases
     */
    public function __construct(
        public readonly array $configs,
        public readonly array $contextConfigs,
        public readonly array $aliases,
    ) {
    }

    /** @param class-string $type */
    public function resolve(string $type): CompiledServiceConfig
    {
        $resolved = $this->aliases[$type] ?? $type;
        if (!isset($this->configs[$resolved])) {
            throw new ServiceNotFoundException($type);
        }
        return $this->configs[$resolved];
    }

    /**
     * @param class-string $type
     * @return class-string
     */
    public function alias(string $type): string
    {
        return $this->aliases[$type] ?? $type;
    }

    /** @param class-string $type */
    public function hasContextConfig(string $type): bool
    {
        return array_key_exists($this->aliases[$type] ?? $type, $this->contextConfigs);
    }

    /** @param class-string $type */
    public function contextConfig(string $type): mixed
    {
        $resolved = $this->aliases[$type] ?? $type;
        if (!array_key_exists($resolved, $this->contextConfigs)) {
            throw new RuntimeException("No context config for {$type}");
        }
        return $this->contextConfigs[$resolved];
    }
}
