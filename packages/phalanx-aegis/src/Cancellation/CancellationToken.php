<?php

declare(strict_types=1);

namespace Phalanx\Cancellation;

use OpenSwoole\Timer;

/**
 * Cooperative cancellation signal.
 *
 * Factories:
 * - none()       — never cancelled (cancel() is a no-op)
 * - create()     — manually cancellable
 * - timeout(s)   — auto-cancels after s seconds via OpenSwoole\Timer; timer is
 *                  cleared on early cancel() so a deferred callback never races
 * - composite(...$tokens) — cancels when ANY source cancels; pre-cancels if any
 *                           source is already cancelled at construction
 *
 * cancel() is idempotent. Listeners fire in registration order. The internal
 * timer ID slot supports the timeout() factory; cancel() always clears it.
 */
class CancellationToken
{
    public bool $isCancelled {
        get => $this->cancelled;
    }

    private bool $cancelled = false;

    /** @var array<int, \Closure(): void> */
    private array $listeners = [];

    private int $listenerSeq = 0;

    private ?int $timerId = null;

    /** @var list<array{self, int}> */
    private array $unregisters = [];

    private bool $immutableNone = false;

    private function __construct()
    {
    }

    public static function none(): self
    {
        $t = new self();
        $t->immutableNone = true;
        return $t;
    }

    public static function create(): self
    {
        return new self();
    }

    public static function timeout(float $seconds): self
    {
        $token = new self();
        $ms = max(1, (int) round($seconds * 1000));
        $timerId = Timer::after($ms, static function () use ($token): void {
            $token->cancel();
        });
        $token->timerId = is_int($timerId) ? $timerId : null;

        return $token;
    }

    /**
     * Composite token. Cancels when any source cancels. Pre-cancels if any source
     * is already cancelled. Listeners registered on source tokens are unregistered
     * when the composite cancels, preventing proportional listener accumulation
     * across concurrent timeout usage.
     */
    public static function composite(self ...$sources): self
    {
        $composite = new self();
        foreach ($sources as $source) {
            if ($source->cancelled) {
                $composite->cancel();
                return $composite;
            }
            $key = $source->onCancel(static function () use ($composite): void {
                $composite->cancel();
            });
            $composite->unregisters[] = [$source, $key];
        }

        return $composite;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new Cancelled();
        }
    }

    public function cancel(): void
    {
        if ($this->cancelled || $this->immutableNone) {
            return;
        }
        $this->cancelled = true;

        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }

        foreach ($this->unregisters as [$source, $key]) {
            $source->offCancel($key);
        }
        $this->unregisters = [];

        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            try {
                $listener();
            } catch (\Throwable) {
            }
        }
    }

    public function release(): void
    {
        foreach ($this->unregisters as [$source, $key]) {
            $source->offCancel($key);
        }
        $this->unregisters = [];
        $this->listeners = [];
    }

    /** @param \Closure(): void $listener */
    public function onCancel(\Closure $listener): int
    {
        if ($this->cancelled) {
            try {
                $listener();
            } catch (\Throwable) {
            }
            return -1;
        }

        $key = $this->listenerSeq++;
        $this->listeners[$key] = $listener;

        return $key;
    }

    public function offCancel(int $key): void
    {
        if ($key < 0) {
            return;
        }
        unset($this->listeners[$key]);
    }
}
