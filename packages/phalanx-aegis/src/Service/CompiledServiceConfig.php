<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;

/**
 * Mutable builder + compiled record. ServiceCatalog returns these directly so
 * registration is fluent (`->factory(...)->onDispose(...)`). Once compile() runs,
 * values are read but not written by ServiceGraph.
 */
final class CompiledServiceConfig implements ServiceConfig
{
    public ?Closure $factoryFn = null;

    /** @var list<class-string> */
    public array $needsTypes = [];

    /** @var list<class-string> */
    public array $interfacesImplemented = [];

    /** @var list<string> */
    public array $tagsList = [];

    /** @var list<Closure(object): void> */
    public array $onInitHooks = [];

    /** @var list<Closure(object): void> */
    public array $onStartupHooks = [];

    /** @var list<Closure(object): void> */
    public array $onDisposeHooks = [];

    /** @var list<Closure(object): void> */
    public array $onShutdownHooks = [];

    public function __construct(
        public readonly string $type,
        public ServiceLifetime $lifetime,
        public bool $lazy = true,
    ) {
    }

    public function lazy(): self
    {
        $this->lazy = true;
        return $this;
    }

    public function eager(): self
    {
        $this->lazy = false;
        return $this;
    }

    public function needs(string ...$types): self
    {
        $this->needsTypes = array_values(array_unique([...$this->needsTypes, ...$types]));
        return $this;
    }

    public function factory(Closure $factory): self
    {
        $this->factoryFn = $factory;
        return $this;
    }

    public function implements(string ...$interfaces): self
    {
        $this->interfacesImplemented = array_values(array_unique([...$this->interfacesImplemented, ...$interfaces]));
        return $this;
    }

    public function tags(string ...$tags): self
    {
        $this->tagsList = array_values(array_unique([...$this->tagsList, ...$tags]));
        return $this;
    }

    public function onInit(Closure $hook): self
    {
        $this->onInitHooks[] = $hook;
        return $this;
    }

    public function onStartup(Closure $hook): self
    {
        $this->onStartupHooks[] = $hook;
        return $this;
    }

    public function onDispose(Closure $hook): self
    {
        $this->onDisposeHooks[] = $hook;
        return $this;
    }

    public function onShutdown(Closure $hook): self
    {
        $this->onShutdownHooks[] = $hook;
        return $this;
    }
}
