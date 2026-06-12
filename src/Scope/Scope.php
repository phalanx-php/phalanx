<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Err\Err;
use Phalanx\Err\Fault;
use Phalanx\Invocation\Executable;
use Phalanx\Mark\Mark;

interface Scope
{
    /**
     * Serial child dispatch: one supervised run, post-supervision outcome.
     *
     * @template TOut
     *
     * @param Executable<TOut> $work
     *
     * @return TOut
     */
    public function run(Executable $work): mixed;

    /**
     * Concurrent children; collects ALL outcomes positionally before routing.
     * The scope never synthesizes an aggregate Err — the parent assembles.
     *
     * @template TOut
     *
     * @param list<Executable<TOut>> $work
     *
     * @return list<TOut>
     */
    public function parallel(array $work): array;

    /**
     * Fan-out over a collection through a work-unit factory.
     *
     * @template TItem
     * @template TOut
     *
     * @param iterable<TItem> $items
     * @param callable(TItem): Executable<TOut> $factory
     *
     * @return list<TOut>
     */
    public function map(iterable $items, callable $factory): array;

    /**
     * First completed child wins; the losers are cancelled.
     *
     * @template TOut
     *
     * @param non-empty-list<Executable<TOut>> $work
     *
     * @return TOut
     */
    public function race(array $work): mixed;

    /** Compensation on any non-success frame outcome (returned Err or escaping Fault). */
    public function onErr(callable $compensation): void;

    /** Cooperative cancellation; propagates to children. */
    public function cancel(): void;

    public function isCancelled(): bool;

    /** Remaining deadline budget; effectively unbounded when no deadline narrows this scope. */
    public function remaining(): Mark;

    /** Narrowed scope with a retry budget; layers only ever narrow. */
    public function withRetry(int $attempts, Backoff $backoff): Scope;

    /** Narrowed scope whose runs and retries stop at the deadline. */
    public function withDeadline(Mark $deadline): Scope;

    /** Narrowed scope with retries suppressed for its dispatches. */
    public function withoutRetry(): Scope;

    /**
     * The only userland Fault conversion surface: absorb at the dispatch
     * site, or return the Fault to keep unwinding.
     *
     * @param callable(Fault): (Err|Fault) $absorb
     */
    public function faultsAs(callable $absorb): Scope;
}
