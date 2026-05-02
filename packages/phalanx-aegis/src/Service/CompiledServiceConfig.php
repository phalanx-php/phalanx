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

    /** @param class-string $type */
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

    /** @param class-string ...$types */
    public function needs(string ...$types): self
    {
        /** @var list<class-string> $needs */
        $needs = array_values(array_unique([...$this->needsTypes, ...$types]));
        $this->needsTypes = $needs;

        return $this;
    }

    public function factory(Closure $factory): self
    {
        $this->factoryFn = $factory;
        return $this;
    }

    /** @param class-string ...$interfaces */
    public function implements(string ...$interfaces): self
    {
        /** @var list<class-string> $implemented */
        $implemented = array_values(array_unique([...$this->interfacesImplemented, ...$interfaces]));
        $this->interfacesImplemented = $implemented;

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
