<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;
use Phalanx\Boot\AppContext;
use RuntimeException;

class ServiceCatalog implements Services
{
    /** @var array<class-string, CompiledServiceConfig> */
    private array $configs = [];

    /** @var array<class-string, mixed> */
    private array $contextConfigs = [];

    /** @var array<class-string, class-string> */
    private array $aliases = [];

    public function __construct(private readonly AppContext $context = new AppContext())
    {
    }

    /** @param class-string $type */
    public function singleton(string $type): ServiceConfig
    {
        return $this->register($type, ServiceLifetime::Singleton, lazy: true);
    }

    /** @param class-string $type */
    public function scoped(string $type): ServiceConfig
    {
        return $this->register($type, ServiceLifetime::Scoped, lazy: true);
    }

    /** @param class-string $type */
    public function eager(string $type): ServiceConfig
    {
        return $this->register($type, ServiceLifetime::Singleton, lazy: false);
    }

    /** @param class-string $type */
    public function has(string $type): bool
    {
        return isset($this->configs[$type])
            || isset($this->aliases[$type])
            || array_key_exists($type, $this->contextConfigs);
    }

    /** @param class-string $type */
    public function config(string $type, Closure $fromContext): void
    {
        $this->contextConfigs[$type] = $fromContext($this->context);
    }

    /**
     * @param class-string $interface
     * @param class-string $concrete
     */
    public function alias(string $interface, string $concrete): void
    {
        $this->aliases[$interface] = $concrete;
    }

    public function compile(): ServiceGraph
    {
        return new ServiceGraph($this->configs, $this->contextConfigs, $this->aliases);
    }

    /** @param class-string $type */
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
