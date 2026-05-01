<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;
use RuntimeException;

class ServiceCatalog implements Services
{
    /** @var array<class-string, CompiledServiceConfig> */
    private array $configs = [];

    /** @var array<class-string, mixed> */
    private array $contextConfigs = [];

    /** @var array<class-string, class-string> */
    private array $aliases = [];

    /** @param array<string, mixed> $context */
    public function __construct(private readonly array $context = [])
    {
    }

    public function singleton(string $type): ServiceConfig
    {
        return $this->register($type, ServiceLifetime::Singleton, lazy: true);
    }

    public function scoped(string $type): ServiceConfig
    {
        return $this->register($type, ServiceLifetime::Scoped, lazy: true);
    }

    public function eager(string $type): ServiceConfig
    {
        return $this->register($type, ServiceLifetime::Singleton, lazy: false);
    }

    public function config(string $type, Closure $fromContext): void
    {
        $this->contextConfigs[$type] = $fromContext($this->context);
    }

    public function alias(string $interface, string $concrete): void
    {
        $this->aliases[$interface] = $concrete;
    }

    public function compile(): ServiceGraph
    {
        return new ServiceGraph($this->configs, $this->contextConfigs, $this->aliases);
    }

    private function register(string $type, ServiceLifetime $lifetime, bool $lazy): CompiledServiceConfig
    {
        if (isset($this->configs[$type])) {
            throw new RuntimeException("Service {$type} already registered");
        }
        $config = new CompiledServiceConfig($type, $lifetime, $lazy);
        $this->configs[$type] = $config;
        return $config;
    }
}
