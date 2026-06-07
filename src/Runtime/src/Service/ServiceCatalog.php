<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Phalanx\Scope\Cancellable;
use Phalanx\Scope\Disposable;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use ReflectionFunction;
use ReflectionNamedType;
use RuntimeException;

class ServiceCatalog implements Services
{
    /** @var list<class-string> */
    private const array SCOPE_BOUNDARY_TYPES = [
        Cancellable::class,
        Disposable::class,
        Scope::class,
        Suspendable::class,
    ];

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

    /** @param class-string $type */
    private static function isScopeBoundaryType(string $type): bool
    {
        if (is_a($type, Scope::class, true)) {
            return true;
        }

        return in_array($type, self::SCOPE_BOUNDARY_TYPES, true);
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

            $this->validateSingletonDependencies($config);
        }
    }

    private function validateSingletonDependencies(CompiledServiceConfig $config): void
    {
        if ($config->lifetime !== ServiceLifetime::Singleton) {
            return;
        }

        foreach ($this->dependencyTypes($config) as $dependency) {
            if (self::isScopeBoundaryType($dependency)) {
                throw new RuntimeException(sprintf(
                    'Service %s is a singleton and depends on scope-bound %s; singleton services cannot capture runtime scopes or scoped services.',
                    $config->type,
                    $dependency,
                ));
            }

            $resolved = $this->aliases[$dependency] ?? $dependency;
            $dependencyConfig = $this->configs[$resolved] ?? null;
            if ($dependencyConfig?->lifetime === ServiceLifetime::Scoped) {
                throw new RuntimeException(sprintf(
                    'Service %s is a singleton and depends on scoped service %s; singleton services cannot capture runtime scopes or scoped services.',
                    $config->type,
                    $dependency,
                ));
            }
        }
    }

    /** @return list<class-string> */
    private function dependencyTypes(CompiledServiceConfig $config): array
    {
        if ($config->needsTypes !== []) {
            return $config->needsTypes;
        }

        $factory = $config->factoryFn;
        if ($factory === null) {
            return [];
        }

        $types = [];
        foreach ((new ReflectionFunction($factory))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $typeName */
            $typeName = $type->getName();
            $types[] = $typeName;
        }

        return $types;
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
