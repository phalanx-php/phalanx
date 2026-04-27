<?php

declare(strict_types=1);

namespace Phalanx\Service;

use Closure;

interface ServiceConfig
{
    public function lazy(): self;

    public function eager(): self;

    public function needs(string ...$types): self;

    public function factory(Closure $factory): self;

    public function implements(string ...$interfaces): self;

    public function tags(string ...$tags): self;

    public function onInit(Closure $hook): self;

    public function onStartup(Closure $hook): self;

    public function onDispose(Closure $hook): self;

    public function onShutdown(Closure $hook): self;
}
