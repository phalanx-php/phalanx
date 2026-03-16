<?php

declare(strict_types=1);

namespace Convoy\Stream;

use Closure;
use Convoy\ExecutionScope;
use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\Contract\StreamSource;
use Convoy\Stream\Terminal;
use React\Stream\ReadableStreamInterface;

final class ScopedStream
{
    private readonly Emitter $emitter;

    public function __construct(
        ReadableStreamInterface|StreamSource|Closure $source,
        private readonly StreamContext $ctx,
    ) {
        $this->emitter = match (true) {
            $source instanceof Emitter => $source,
            $source instanceof StreamSource => Emitter::produce(
                static function (Channel $ch, StreamContext $ctx) use ($source): void {
                    foreach ($source($ctx) as $value) {
                        $ch->emit($value);
                    }
                },
            ),
            $source instanceof ReadableStreamInterface => Emitter::stream($source),
            $source instanceof Closure => Emitter::produce($source),
        };
    }

    public static function from(ExecutionScope $scope, ReadableStreamInterface|StreamSource|Closure $source): self
    {
        return new self($source, $scope);
    }

    /** @param callable(mixed): mixed $fn */
    public function map(callable $fn): self
    {
        return new self($this->emitter->map($fn), $this->ctx);
    }

    /** @param callable(mixed): bool $predicate */
    public function filter(callable $predicate): self
    {
        return new self($this->emitter->filter($predicate), $this->ctx);
    }

    public function take(int $n): self
    {
        return new self($this->emitter->take($n), $this->ctx);
    }

    public function throttle(float $seconds): self
    {
        return new self($this->emitter->throttle($seconds), $this->ctx);
    }

    public function debounce(float $seconds): self
    {
        return new self($this->emitter->debounce($seconds), $this->ctx);
    }

    public function bufferWindow(int $count, float $seconds): self
    {
        return new self($this->emitter->bufferWindow($count, $seconds), $this->ctx);
    }

    public function merge(Emitter ...$others): self
    {
        return new self($this->emitter->merge(...$others), $this->ctx);
    }

    public function distinct(): self
    {
        return new self($this->emitter->distinct(), $this->ctx);
    }

    /** @param callable(mixed): mixed $keyFn */
    public function distinctBy(callable $keyFn): self
    {
        return new self($this->emitter->distinctBy($keyFn), $this->ctx);
    }

    public function sample(float $seconds): self
    {
        return new self($this->emitter->sample($seconds), $this->ctx);
    }

    /** @param callable(StreamContext): void $fn */
    public function onStart(callable $fn): self
    {
        return new self($this->emitter->onStart($fn), $this->ctx);
    }

    /** @param callable(mixed, StreamContext): void $fn */
    public function onEach(callable $fn): self
    {
        return new self($this->emitter->onEach($fn), $this->ctx);
    }

    /** @param callable(\Throwable, StreamContext): void $fn */
    public function onError(callable $fn): self
    {
        return new self($this->emitter->onError($fn), $this->ctx);
    }

    /** @param callable(StreamContext): void $fn */
    public function onComplete(callable $fn): self
    {
        return new self($this->emitter->onComplete($fn), $this->ctx);
    }

    /** @param callable(StreamContext): void $fn */
    public function onDispose(callable $fn): self
    {
        return new self($this->emitter->onDispose($fn), $this->ctx);
    }

    public function consume(): void
    {
        (new Terminal\Drain($this->emitter))($this->ctx);
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return (new Terminal\Collect($this->emitter))($this->ctx);
    }

    public function reduce(callable $fn, mixed $initial = null): mixed
    {
        return (new Terminal\Reduce($this->emitter, $fn, $initial))($this->ctx);
    }

    public function first(): mixed
    {
        return (new Terminal\First($this->emitter))($this->ctx);
    }
}
