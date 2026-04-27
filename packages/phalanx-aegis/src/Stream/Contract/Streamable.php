<?php

declare(strict_types=1);

namespace Phalanx\Stream\Contract;

trait Streamable
{
    private StreamHooks $streamHooks;

    private bool $unorderedFlag = false;

    /** @param callable(StreamContext): void $fn */
    public function onStart(callable $fn): static
    {
        $clone = clone $this;
        $clone->streamHooks = $this->streamHooks->clone();
        $clone->streamHooks->onStart[] = $fn;

        return $clone;
    }

    /** @param callable(mixed, StreamContext): void $fn */
    public function onEach(callable $fn): static
    {
        $clone = clone $this;
        $clone->streamHooks = $this->streamHooks->clone();
        $clone->streamHooks->onEach[] = $fn;

        return $clone;
    }

    /** @param callable(\Throwable, StreamContext): void $fn */
    public function onError(callable $fn): static
    {
        $clone = clone $this;
        $clone->streamHooks = $this->streamHooks->clone();
        $clone->streamHooks->onError[] = $fn;

        return $clone;
    }

    /** @param callable(StreamContext): void $fn */
    public function onComplete(callable $fn): static
    {
        $clone = clone $this;
        $clone->streamHooks = $this->streamHooks->clone();
        $clone->streamHooks->onComplete[] = $fn;

        return $clone;
    }

    /** @param callable(StreamContext): void $fn */
    public function onDispose(callable $fn): static
    {
        $clone = clone $this;
        $clone->streamHooks = $this->streamHooks->clone();
        $clone->streamHooks->onDispose[] = $fn;

        return $clone;
    }

    public function unordered(): static
    {
        $clone = clone $this;
        $clone->unorderedFlag = true;

        return $clone;
    }

    protected function initStreamState(): void
    {
        $this->streamHooks = new StreamHooks();
    }

    protected function copyStreamState(self $target): void
    {
        $target->streamHooks = $this->streamHooks->clone();
        $target->unorderedFlag = $this->unorderedFlag;
    }

    protected function fireOnStart(StreamContext $ctx): void
    {
        foreach ($this->streamHooks->onStart as $fn) {
            $fn($ctx);
        }
    }

    protected function fireOnEach(mixed $item, StreamContext $ctx): void
    {
        foreach ($this->streamHooks->onEach as $fn) {
            $fn($item, $ctx);
        }
    }

    protected function fireOnError(\Throwable $e, StreamContext $ctx): void
    {
        foreach ($this->streamHooks->onError as $fn) {
            $fn($e, $ctx);
        }
    }

    protected function fireOnComplete(StreamContext $ctx): void
    {
        foreach ($this->streamHooks->onComplete as $fn) {
            $fn($ctx);
        }
    }

    protected function fireOnDispose(StreamContext $ctx): void
    {
        foreach ($this->streamHooks->onDispose as $fn) {
            $fn($ctx);
        }
    }
}
