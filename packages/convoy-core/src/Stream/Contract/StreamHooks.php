<?php

declare(strict_types=1);

namespace Convoy\Stream\Contract;

final class StreamHooks
{
    /** @var list<callable(StreamContext): void> */
    public array $onStart = [];

    /** @var list<callable(mixed, StreamContext): void> */
    public array $onEach = [];

    /** @var list<callable(\Throwable, StreamContext): void> */
    public array $onError = [];

    /** @var list<callable(StreamContext): void> */
    public array $onComplete = [];

    /** @var list<callable(StreamContext): void> */
    public array $onDispose = [];

    public function merge(self $other): self
    {
        $merged = new self();
        $merged->onStart = [...$this->onStart, ...$other->onStart];
        $merged->onEach = [...$this->onEach, ...$other->onEach];
        $merged->onError = [...$this->onError, ...$other->onError];
        $merged->onComplete = [...$this->onComplete, ...$other->onComplete];
        $merged->onDispose = [...$this->onDispose, ...$other->onDispose];

        return $merged;
    }

    public function clone(): self
    {
        $clone = new self();
        $clone->onStart = $this->onStart;
        $clone->onEach = $this->onEach;
        $clone->onError = $this->onError;
        $clone->onComplete = $this->onComplete;
        $clone->onDispose = $this->onDispose;

        return $clone;
    }
}
