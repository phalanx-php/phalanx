<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;

final readonly class ServiceConfigBuilder implements ServiceConfig
{
    public function __construct(
        private ServiceCatalog $catalog,
        private string $type,
    ) {
    }

    public function lazy(): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->asLazy());
    }

    public function eager(): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->asEager());
    }

    public function needs(string ...$types): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withDependencies(...$types));
    }

    public function factory(Closure $factory): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withFactory($factory));
    }

    public function implements(string ...$interfaces): self
    {
        $builder = $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withImplements(...$interfaces));

        foreach ($interfaces as $interface) {
            $this->catalog->alias($interface, $this->type);
        }

        return $builder;
    }

    public function tags(string ...$tags): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withTags(...$tags));
    }

    public function onInit(Closure $hook): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withLifecycleHook('init', $hook));
    }

    public function onStartup(Closure $hook): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withLifecycleHook('startup', $hook));
    }

    public function onDispose(Closure $hook): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withLifecycleHook('dispose', $hook));
    }

    public function onShutdown(Closure $hook): self
    {
        return $this->update(fn(ServiceDefinition $d): ServiceDefinition => $d->withLifecycleHook('shutdown', $hook));
    }

    private function update(Closure $transform): self
    {
        $current = $this->catalog->getDefinition($this->type);

        if (!$current instanceof ServiceDefinition) {
            throw new \LogicException("Definition for {$this->type} not found");
        }

        $this->catalog->updateDefinition($this->type, $transform($current));

        return $this;
    }
}
