<?php

declare(strict_types=1);

namespace Convoy\Lifecycle;

use Closure;

final class LifecycleCallbacks
{
    public function __construct(
        /** @var list<Closure> */
        public private(set) array $onInit = [],
        /** @var list<Closure> */
        public private(set) array $onStartup = [],
        /** @var list<Closure> */
        public private(set) array $onReady = [],
        /** @var list<Closure> */
        public private(set) array $onDispose = [],
        /** @var list<Closure> */
        public private(set) array $onShutdown = [],
    ) {
    }

    public function withHook(string $phase, Closure $hook): self
    {
        $clone = clone $this;

        match ($phase) {
            'init', 'onInit' => $clone->onInit = [...$this->onInit, $hook],
            'startup', 'onStartup' => $clone->onStartup = [...$this->onStartup, $hook],
            'ready', 'onReady' => $clone->onReady = [...$this->onReady, $hook],
            'dispose', 'onDispose' => $clone->onDispose = [...$this->onDispose, $hook],
            'shutdown', 'onShutdown' => $clone->onShutdown = [...$this->onShutdown, $hook],
            default => throw new \InvalidArgumentException("Unknown lifecycle phase: $phase"),
        };

        return $clone;
    }

    public function hasInit(): bool
    {
        return count($this->onInit) > 0;
    }

    public function hasStartup(): bool
    {
        return count($this->onStartup) > 0;
    }

    public function hasReady(): bool
    {
        return count($this->onReady) > 0;
    }

    public function hasDispose(): bool
    {
        return count($this->onDispose) > 0;
    }

    public function hasShutdown(): bool
    {
        return count($this->onShutdown) > 0;
    }

    public function merge(self $other): self
    {
        $clone = clone $this;
        $clone->onInit = [...$this->onInit, ...$other->onInit];
        $clone->onStartup = [...$this->onStartup, ...$other->onStartup];
        $clone->onReady = [...$this->onReady, ...$other->onReady];
        $clone->onDispose = [...$this->onDispose, ...$other->onDispose];
        $clone->onShutdown = [...$this->onShutdown, ...$other->onShutdown];
        return $clone;
    }
}
