<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Closure;
use OpenSwoole\Coroutine\Channel;
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
 *   'waiters'   => list<Channel>,
 * ]
 */
class SingleflightGroup
{
    /** @var array<string, array{in_flight: bool, result: mixed, error: ?Throwable, waiters: list<Channel>}> */
    private array $state = [];

    /**
     * @template T
     * @param Closure(): T $execute
     * @return T
     */
    public function do(string $key, Closure $execute): mixed
    {
        if (isset($this->state[$key]) && $this->state[$key]['in_flight']) {
            $waiter = new Channel(1);
            $this->state[$key]['waiters'][] = $waiter;
            $msg = $waiter->pop();
            if ($msg[0] === 'err') {
                throw $msg[1];
            }
            return $msg[1];
        }

        $this->state[$key] = ['in_flight' => true, 'result' => null, 'error' => null, 'waiters' => []];

        try {
            $result = $execute();
        } catch (Throwable $e) {
            $waiters = $this->state[$key]['waiters'];
            unset($this->state[$key]);
            foreach ($waiters as $waiter) {
                $waiter->push(['err', $e]);
            }
            throw $e;
        }

        $waiters = $this->state[$key]['waiters'];
        unset($this->state[$key]);
        foreach ($waiters as $waiter) {
            $waiter->push(['ok', $result]);
        }
        return $result;
    }

    public function pending(): int
    {
        return count($this->state);
    }
}
