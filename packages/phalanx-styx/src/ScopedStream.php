<?php

declare(strict_types=1);

namespace Phalanx\Styx;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Scope\Stream\StreamSource;
use Phalanx\Styx\Terminal\Collect;
use Phalanx\Styx\Terminal\Drain;
use Phalanx\Styx\Terminal\First;
use Phalanx\Styx\Terminal\Reduce;

final class ScopedStream
{
    private readonly Emitter $emitter;

    /** @param StreamSource<mixed>|Closure(Channel, StreamContext): void $source */
    public function __construct(
        StreamSource|Closure $source,
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
            $source instanceof Closure => Emitter::produce($source),
        };
    }

    /** @param StreamSource<mixed>|Closure(Channel, StreamContext): void $source */
    public static function from(ExecutionScope $scope, StreamSource|Closure $source): self
    {
        return new self($source, $scope);
    }

    /** @param Closure(mixed): mixed $fn */
    public function map(Closure $fn): self
    {
        return new self($this->emitter->map($fn), $this->ctx);
    }

    /** @param Closure(mixed): bool $predicate */
    public function filter(Closure $predicate): self
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

    /** @param Closure(mixed): mixed $keyFn */
    public function distinctBy(Closure $keyFn): self
    {
        return new self($this->emitter->distinctBy($keyFn), $this->ctx);
    }

    public function sample(float $seconds): self
    {
        return new self($this->emitter->sample($seconds), $this->ctx);
    }

    /** @param Closure(StreamContext): void $fn */
    public function onStart(Closure $fn): self
    {
        $this->emitter->onStart($fn);
        return $this;
    }

    /** @param Closure(mixed, StreamContext): void $fn */
    public function onEach(Closure $fn): self
    {
        $this->emitter->onEach($fn);
        return $this;
    }

    /** @param Closure(\Throwable, StreamContext): void $fn */
    public function onError(Closure $fn): self
    {
        $this->emitter->onError($fn);
        return $this;
    }

    /** @param Closure(StreamContext): void $fn */
    public function onComplete(Closure $fn): self
    {
        $this->emitter->onComplete($fn);
        return $this;
    }

    /** @param Closure(StreamContext): void $fn */
    public function onDispose(Closure $fn): self
    {
        $this->emitter->onDispose($fn);
        return $this;
    }

    public function consume(): void
    {
        (new Drain($this->emitter))($this->ctx);
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        return (new Collect($this->emitter))($this->ctx);
    }

    /** @param Closure(mixed, mixed): mixed $fn */
    public function reduce(Closure $fn, mixed $initial = null): mixed
    {
        return (new Reduce($this->emitter, $fn, $initial))($this->ctx);
    }

    public function first(): mixed
    {
        return (new First($this->emitter))($this->ctx);
    }
}
