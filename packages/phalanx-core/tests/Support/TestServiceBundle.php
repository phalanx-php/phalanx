<?php

declare(strict_types=1);

namespace Phalanx\Tests\Support;

use Closure;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\Services;

final class TestServiceBundle implements ServiceBundle
{
    /** @var list<Closure(ServiceCatalog, array): void> */
    private array $registrations = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, Closure> */
    private array $configs = [];

    public static function create(): self
    {
        return new self();
    }

    public function singleton(string $type, ?Closure $factory = null): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type, $factory): void {
            $config = $catalog->singleton($type);
            if ($factory !== null) {
                $config->factory($factory);
            }
        };

        return $this;
    }

    public function scoped(string $type, ?Closure $factory = null): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type, $factory): void {
            $config = $catalog->scoped($type);
            if ($factory !== null) {
                $config->factory($factory);
            }
        };

        return $this;
    }

    public function eager(string $type, ?Closure $factory = null): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type, $factory): void {
            $config = $catalog->eager($type);
            if ($factory !== null) {
                $config->factory($factory);
            }
        };

        return $this;
    }

    public function alias(string $interface, string $concrete): self
    {
        $this->aliases[$interface] = $concrete;
        return $this;
    }

    public function config(string $type, Closure $fromContext): self
    {
        $this->configs[$type] = $fromContext;
        return $this;
    }

    public function withDependencies(string $type, string ...$deps): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type, $deps): void {
            $def = $catalog->getDefinition($type);
            if ($def !== null) {
                $catalog->updateDefinition($type, $def->withDependencies(...$deps));
            }
        };

        return $this;
    }

    public function withLifecycle(string $type, string $phase, Closure $hook): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type, $phase, $hook): void {
            $def = $catalog->getDefinition($type);
            if ($def !== null) {
                $catalog->updateDefinition($type, $def->withLifecycleHook($phase, $hook));
            }
        };

        return $this;
    }

    public function asLazy(string $type): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type): void {
            $def = $catalog->getDefinition($type);
            if ($def !== null) {
                $catalog->updateDefinition($type, $def->asLazy());
            }
        };

        return $this;
    }

    public function asEager(string $type): self
    {
        $this->registrations[] = function (ServiceCatalog $catalog) use ($type): void {
            $def = $catalog->getDefinition($type);
            if ($def !== null) {
                $catalog->updateDefinition($type, $def->asEager());
            }
        };

        return $this;
    }

    public function services(Services $services, array $context): void
    {
        if (!$services instanceof ServiceCatalog) {
            throw new \InvalidArgumentException('TestServiceBundle requires ServiceCatalog');
        }

        foreach ($this->registrations as $registration) {
            $registration($services, $context);
        }

        foreach ($this->aliases as $interface => $concrete) {
            $services->alias($interface, $concrete);
        }

        foreach ($this->configs as $type => $fromContext) {
            $services->config($type, $fromContext);
        }
    }
}
