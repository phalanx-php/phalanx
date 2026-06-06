<?php

declare(strict_types=1);

namespace Phalanx\Stream;

use Closure;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Stream\StreamSource;
use Phalanx\Stream\Terminal\Collect;
use Phalanx\Stream\Terminal\Drain;
use Phalanx\Stream\Terminal\First;
use Phalanx\Stream\Terminal\Reduce;

final class Scoped
{
    private readonly Emitter $emitter;

    /** @param StreamSource<mixed>|Closure(ExecutionScope, Channel): void $source */
    public function __construct(
        private readonly ExecutionScope $scope,
        StreamSource|Closure $source,
    ) {
        $this->emitter = match (true) {
            $source instanceof Emitter => $source,
            $source instanceof StreamSource => Emitter::produce(
                static function (ExecutionScope $scope, Channel $ch) use ($source): void {
                    foreach ($source($scope) as $value) {
                        $ch->emit($value);
                    }
                },
            ),
            $source instanceof Closure => Emitter::produce($source),
        };
    }

    /** @param StreamSource<mixed>|Closure(ExecutionScope, Channel): void $source */
    public static function from(ExecutionScope $scope, StreamSource|Closure $source): self
    {
        return new self($scope, $source);
    }

    /** @param Closure(mixed): mixed $fn */
    public function map(Closure $fn): self
    {
        return new self($this->scope, $this->emitter->map($fn));
    }

    /** @param Closure(mixed): bool $predicate */
    public function filter(Closure $predicate): self
    {
        return new self($this->scope, $this->emitter->filter($predicate));
    }

    public function take(int $n): self
    {
        return new self($this->scope, $this->emitter->take($n));
    }

    public function throttle(float $seconds): self
    {
        return new self($this->scope, $this->emitter->throttle($seconds));
    }

    public function debounce(float $seconds): self
    {
        return new self($this->scope, $this->emitter->debounce($seconds));
    }

    public function bufferWindow(int $count, float $seconds): self
    {
        return new self($this->scope, $this->emitter->bufferWindow($count, $seconds));
    }

    public function merge(Emitter ...$others): self
    {
        return new self($this->scope, $this->emitter->merge(...$others));
    }

    public function distinct(): self
    {
        return new self($this->scope, $this->emitter->distinct());
    }

    /** @param Closure(mixed): mixed $keyFn */
    public function distinctBy(Closure $keyFn): self
    {
        return new self($this->scope, $this->emitter->distinctBy($keyFn));
    }

    public function sample(float $seconds): self
    {
        return new self($this->scope, $this->emitter->sample($seconds));
    }

    /** @param Closure(ExecutionScope): void $fn */
    public function onStart(Closure $fn): self
    {
        $this->emitter->onStart($fn);

        return $this;
    }

    /** @param Closure(ExecutionScope, mixed): void $fn */
    public function onEach(Closure $fn): self
    {
        $this->emitter->onEach($fn);

        return $this;
    }

    /** @param Closure(ExecutionScope, \Throwable): void $fn */
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
