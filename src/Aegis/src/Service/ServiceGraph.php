<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Exception\ServiceNotFoundException;

/**
 * Compiled, read-only view of the service registry. Built once by ServiceCatalog::compile()
 * and shared across all scopes for the application lifetime.
 */
final class ServiceGraph
{
    /**
     * @param array<class-string, CompiledServiceConfig> $configs
     * @param array<class-string, class-string> $aliases
     */
    public function __construct(
        public readonly array $configs,
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
}
