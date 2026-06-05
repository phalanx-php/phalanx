<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Support;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\Services;

final class TestServiceBundle extends ServiceBundle
{
    /** @var list<Closure(ServiceCatalog, AppContext): void> */
    private array $registrations = [];

    /** @var array<class-string, class-string> */
    private array $aliases = [];

    public static function create(): self
    {
        return new self();
    }

    /** @param class-string $type */
    public function singleton(string $type, ?Closure $factory = null): self
    {
        $this->registrations[] = static function (ServiceCatalog $catalog) use ($type, $factory): void {
            $config = $catalog->singleton($type);
            if ($factory !== null) {
                $config->factory($factory);
            }
        };

        return $this;
    }

    /** @param class-string $type */
    public function scoped(string $type, ?Closure $factory = null): self
    {
        $this->registrations[] = static function (ServiceCatalog $catalog) use ($type, $factory): void {
            $config = $catalog->scoped($type);
            if ($factory !== null) {
                $config->factory($factory);
            }
        };

        return $this;
    }

    /** @param class-string $type */
    public function eager(string $type, ?Closure $factory = null): self
    {
        $this->registrations[] = static function (ServiceCatalog $catalog) use ($type, $factory): void {
            $config = $catalog->eager($type);
            if ($factory !== null) {
                $config->factory($factory);
            }
        };

        return $this;
    }

    /**
     * @param class-string $interface
     * @param class-string $concrete
     */
    public function alias(string $interface, string $concrete): self
    {
        $this->aliases[$interface] = $concrete;
        return $this;
    }

    public function services(Services $services, AppContext $context): void
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
    }
}
