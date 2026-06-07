<?php

declare(strict_types=1);

namespace Phalanx\Service;

use RuntimeException;

class ServiceCatalog implements Services
{
    /** @var array<class-string, CompiledServiceConfig> */
    private array $configs = [];

    /** @var array<class-string, class-string> */
    private array $aliases = [];

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
            || isset($this->aliases[$type]);
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
        $this->validateLifecycleHooks();

        return new ServiceGraph($this->configs, $this->aliases);
    }

    private static function assertEagerSingletonLifecycleHook(CompiledServiceConfig $config, string $hook): void
    {
        if ($config->lifetime === ServiceLifetime::Singleton && !$config->lazy) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Service %s registered %s(), but startup/ready lifecycle hooks are only valid on eager singleton services.',
            $config->type,
            $hook,
        ));
    }

    private function validateLifecycleHooks(): void
    {
        foreach ($this->configs as $config) {
            if ($config->onStartupHooks !== []) {
                self::assertEagerSingletonLifecycleHook($config, 'onStartup');
            }

            if ($config->onReadyHooks !== []) {
                self::assertEagerSingletonLifecycleHook($config, 'onReady');
            }
        }
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
