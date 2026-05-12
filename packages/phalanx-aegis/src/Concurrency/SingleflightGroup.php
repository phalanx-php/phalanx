<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Closure;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Throwable;

/**
 * In-flight deduplication. If a key is already executing, subsequent callers
 * suspend on a per-call waiter channel and receive the in-flight result.
 *
 * No lock needed: Swoole's coroutine scheduler is non-preemptive on a single
 * thread, so all state mutations between yield points are atomic from any
 * single coroutine's view. The only yield points in do() are the in-flight
 * $execute() and the waiter pop.
 *
 * @internal Per-key state structure:
 * [
 *   'in_flight' => bool,
 *   'result'    => mixed,
 *   'error'     => ?Throwable,
 *   'waiters'   => array<int, Channel>,
 * ]
 */
class SingleflightGroup
{
    /** @var array<string, array{in_flight: bool, result: mixed, error: ?Throwable, waiters: array<int, Channel>}> */
    private array $state = [];

    private int $waiterSeq = 0;

    /**
     * @template T
     * @param Closure(): T $execute
     * @param Closure|null $onWait Called when a duplicate caller begins waiting; may return a clear callback.
     * @return T
     */
    public function do(
        string $key,
        Closure $execute,
        ?CancellationToken $cancellation = null,
        ?Closure $onWait = null,
    ): mixed {
        $token = $cancellation ?? CancellationToken::none();
        $token->throwIfCancelled();

        if (isset($this->state[$key]) && $this->state[$key]['in_flight']) {
            return $this->waitForInflight($key, $token, $onWait);
        }

        $this->state[$key] = ['in_flight' => true, 'result' => null, 'error' => null, 'waiters' => []];

        try {
            $result = $execute();
        } catch (Throwable $e) {
            $this->wakeWaiters($key, ['err', $e]);
            throw $e;
        }

        $this->wakeWaiters($key, ['ok', $result]);
        return $result;
    }

    public function pending(): int
    {
        return count($this->state);
    }

    /** @param Closure|null $onWait */
    private function waitForInflight(
        string $key,
        CancellationToken $token,
        ?Closure $onWait,
    ): mixed {
        $waiter = new Channel(1);
        $waiterId = ++$this->waiterSeq;
        $this->state[$key]['waiters'][$waiterId] = $waiter;

        $group = $this;
        $cancelKey = $token->onCancel(static function () use ($group, $key, $waiterId, $waiter): void {
            $group->removeWaiter($key, $waiterId);
            $waiter->push(['cancelled', new Cancelled("singleflight '{$key}' cancelled")], 0.001);
        });
        $clearWait = $onWait !== null ? $onWait() : null;

        try {
            $msg = $waiter->pop();
            if (!is_array($msg) || $msg === []) {
                throw new Cancelled("singleflight '{$key}' waiter closed");
            }
            if ($msg[0] === 'err' || $msg[0] === 'cancelled') {
                throw $msg[1];
            }
            return $msg[1];
        } finally {
            $token->offCancel($cancelKey);
            if ($clearWait !== null) {
                $clearWait();
            }
            $this->removeWaiter($key, $waiterId);
        }
    }

    private function removeWaiter(string $key, int $waiterId): void
    {
        if (!isset($this->state[$key])) {
            return;
        }

        unset($this->state[$key]['waiters'][$waiterId]);
    }

    /** @param array{0: 'ok'|'err'|'cancelled', 1: mixed} $message */
    private function wakeWaiters(string $key, array $message): void
    {
        $waiters = $this->state[$key]['waiters'] ?? [];
        unset($this->state[$key]);

        foreach ($waiters as $waiter) {
            $waiter->push($message, 0.001);
        }
    }
}
