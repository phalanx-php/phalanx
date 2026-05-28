<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

use Closure;
use Phalanx\Scope\TaskScope;
use ReflectionFunction;
use RuntimeException;
use Throwable;

final class Resource
{
    private(set) bool $loading = false;
    private(set) mixed $value = null;
    private(set) ?Throwable $error = null;
    private(set) bool $ok = false;

    private bool $disposed = false;
    private int $generation = 0;

    public function __construct(
        private readonly Closure $fetcher,
        private readonly ?TaskScope $scope = null,
        private readonly ?Closure $onDirty = null,
    ) {
        if (!new ReflectionFunction($fetcher)->isStatic()) {
            throw new RuntimeException('Resource fetcher must be a static closure.');
        }

        if ($onDirty !== null && !new ReflectionFunction($onDirty)->isStatic()) {
            throw new RuntimeException('Resource onDirty callback must be a static closure.');
        }
    }

    public function refresh(mixed $key = null): void
    {
        if ($this->disposed) {
            return;
        }

        $this->loading = true;
        $this->error = null;
        $this->onDirty?->__invoke();

        $gen = ++$this->generation;
        $fetcher = $this->fetcher;

        if ($this->scope !== null) {
            $this->fetchAsync($gen, $key, $fetcher);
        } else {
            $this->fetchSync($gen, $key, $fetcher);
        }
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;
        $this->loading = false;
        $this->generation++;
    }

    private function fetchSync(int $gen, mixed $key, Closure $fetcher): void
    {
        try {
            $result = $fetcher($key);
        } catch (Throwable $e) {
            if ($this->generation === $gen && !$this->disposed) {
                $this->error = $e;
                $this->loading = false;
                $this->onDirty?->__invoke();
            }

            return;
        }

        if ($this->generation !== $gen || $this->disposed) {
            return;
        }

        $this->value = $result;
        $this->ok = true;
        $this->loading = false;
        $this->onDirty?->__invoke();
    }

    private function fetchAsync(int $gen, mixed $key, Closure $fetcher): void
    {
        $weakSelf = \WeakReference::create($this);

        $this->scope->execute(static function () use ($weakSelf, $gen, $key, $fetcher): void {
            try {
                $result = $fetcher($key);
            } catch (Throwable $e) {
                $self = $weakSelf->get();
                if ($self !== null && $self->generation === $gen && !$self->disposed) {
                    $self->error = $e;
                    $self->loading = false;
                    $self->onDirty?->__invoke();
                }

                return;
            }

            $self = $weakSelf->get();
            if ($self === null || $self->generation !== $gen || $self->disposed) {
                return;
            }

            $self->value = $result;
            $self->ok = true;
            $self->loading = false;
            $self->onDirty?->__invoke();
        });
    }
}
