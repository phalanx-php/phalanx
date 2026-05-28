<?php

declare(strict_types=1);

namespace AegisSwoole\Cancellation;

use Closure;
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
    private(set) bool $isCancelled = false;

    private int $listenerSeq = 0;

    private ?int $timerId = null;

    private bool $immutableNone = false;

    /** @var array<int, Closure(): void> */
    private array $listeners = [];

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
        $token->timerId = Timer::after($ms, static function () use ($token): void {
            $token->cancel();
        });
        return $token;
    }

    /**
     * Composite token. Cancels when any source cancels. Pre-cancels if any source
     * is already cancelled. Subscriptions on sources are not unregistered if the
     * composite is GC'd before its sources, but since the listener only flips
     * an already-flipped flag (idempotent cancel), this is benign.
     */
    public static function composite(self ...$sources): self
    {
        $composite = new self();
        foreach ($sources as $source) {
            if ($source->isCancelled) {
                $composite->cancel();
                return $composite;
            }
            $source->onCancel(static function () use ($composite): void {
                $composite->cancel();
            });
        }
        return $composite;
    }

    public function throwIfCancelled(): void
    {
        if ($this->isCancelled) {
            throw new Cancelled();
        }
    }

    public function cancel(): void
    {
        if ($this->isCancelled || $this->immutableNone) {
            return;
        }
        $this->isCancelled = true;

        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }

        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            try {
                $listener();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param Closure(): void $listener
     * @return Closure(): void  unregister handle
     */
    public function onCancel(Closure $listener): Closure
    {
        if ($this->isCancelled) {
            try {
                $listener();
            } catch (\Throwable) {
            }
            return static fn() => null;
        }

        $key = $this->listenerSeq++;
        $this->listeners[$key] = $listener;

        return function () use ($key): void {
            unset($this->listeners[$key]);
        };
    }
}
