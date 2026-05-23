<?php

declare(strict_types=1);

namespace Phalanx\Styx;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Stream\StreamSource;
use Phalanx\Styx\Terminal\Collect;
use Phalanx\Styx\Terminal\Drain;
use Phalanx\Styx\Terminal\First;
use Phalanx\Styx\Terminal\Reduce;

final class ScopedStream
{
    private readonly Emitter $emitter;

    /** @param StreamSource<mixed>|Closure(Channel, ExecutionScope): void $source */
    public function __construct(
        StreamSource|Closure $source,
        private readonly ExecutionScope $scope,
    ) {
        $this->emitter = match (true) {
            $source instanceof Emitter => $source,
            $source instanceof StreamSource => Emitter::produce(
                static function (Channel $ch, ExecutionScope $scope) use ($source): void {
                    foreach ($source($scope) as $value) {
                        $ch->emit($value);
                    }
                },
            ),
            $source instanceof Closure => Emitter::produce($source),
        };
    }

    /** @param StreamSource<mixed>|Closure(Channel, ExecutionScope): void $source */
    public static function from(ExecutionScope $scope, StreamSource|Closure $source): self
    {
        return new self($source, $scope);
    }

    /** @param Closure(mixed): mixed $fn */
    public function map(Closure $fn): self
    {
        return new self($this->emitter->map($fn), $this->scope);
    }

    /** @param Closure(mixed): bool $predicate */
    public function filter(Closure $predicate): self
    {
        return new self($this->emitter->filter($predicate), $this->scope);
    }

    public function take(int $n): self
    {
        return new self($this->emitter->take($n), $this->scope);
    }

    public function throttle(float $seconds): self
    {
        return new self($this->emitter->throttle($seconds), $this->scope);
    }

    public function debounce(float $seconds): self
    {
        return new self($this->emitter->debounce($seconds), $this->scope);
    }

    public function bufferWindow(int $count, float $seconds): self
    {
        return new self($this->emitter->bufferWindow($count, $seconds), $this->scope);
    }

    public function merge(Emitter ...$others): self
    {
        return new self($this->emitter->merge(...$others), $this->scope);
    }

    public function distinct(): self
    {
        return new self($this->emitter->distinct(), $this->scope);
    }

    /** @param Closure(mixed): mixed $keyFn */
    public function distinctBy(Closure $keyFn): self
    {
        return new self($this->emitter->distinctBy($keyFn), $this->scope);
    }

    public function sample(float $seconds): self
    {
        return new self($this->emitter->sample($seconds), $this->scope);
    }

    /** @param Closure(ExecutionScope): void $fn */
    public function onStart(Closure $fn): self
    {
        $this->emitter->onStart($fn);
        return $this;
    }

    /** @param Closure(mixed, ExecutionScope): void $fn */
    public function onEach(Closure $fn): self
    {
        $this->emitter->onEach($fn);
        return $this;
    }

    /** @param Closure(\Throwable, ExecutionScope): void $fn */
    public function onError(Closure $fn): self
    {
        $this->emitter->onError($fn);
        return $this;
    }

    /** @param Closure(ExecutionScope): void $fn */
    public function onComplete(Closure $fn): self
    {
        $this->emitter->onComplete($fn);
        return $this;
    }

    /** @param Closure(ExecutionScope): void $fn */
    public function onDispose(Closure $fn): self
    {
        $this->emitter->onDispose($fn);
        return $this;
    }

    public function consume(): void
    {
        (new Drain($this->emitter))($this->scope);
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        return (new Collect($this->emitter))($this->scope);
    }

    /** @param Closure(mixed, mixed): mixed $fn */
    public function reduce(Closure $fn, mixed $initial = null): mixed
    {
        return (new Reduce($this->emitter, $fn, $initial))($this->scope);
    }

    public function first(): mixed
    {
        return (new First($this->emitter))($this->scope);
    }
}
