<?php

declare(strict_types=1);

namespace AegisSwoole\Service;

use Closure;

interface ServiceConfig
{
    public function lazy(): self;

    public function eager(): self;

    /** @param class-string ...$types */
    public function needs(string ...$types): self;

    /**
     * Factory closure called with the resolved dependencies declared via
     * `needs(...)`, in registration order. Return type must be the registered
     * service class. Closure parameter types are not narrowed at the interface
     * level because they vary per registration.
     */
    public function factory(Closure $factory): self;

    /** @param class-string ...$interfaces */
    public function implements(string ...$interfaces): self;

    public function tags(string ...$tags): self;

    /** @param Closure(object): void $hook */
    public function onInit(Closure $hook): self;

    /** @param Closure(object): void $hook */
    public function onStartup(Closure $hook): self;

    /** @param Closure(object): void $hook */
    public function onDispose(Closure $hook): self;

    /** @param Closure(object): void $hook */
    public function onShutdown(Closure $hook): self;
}
