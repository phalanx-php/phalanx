<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use InvalidArgumentException;
use Phalanx\Engine\CancelFlag;
use Phalanx\Engine\FaultSignal;
use Phalanx\Engine\FrameCtx;
use Phalanx\Engine\InvokeSignature;
use Phalanx\Engine\Wiring;
use Phalanx\Err\Err;
use Phalanx\Err\Fault;
use Phalanx\Err\FaultBorn;
use Phalanx\Err\FaultEscaped;
use Phalanx\Err\Retryable;
use Phalanx\Err\Severity;
use Phalanx\Invocation\Attempt;
use Phalanx\Invocation\Executable;
use Phalanx\Invocation\InvocationId;
use Phalanx\Mark\Mark;
use Throwable;

/**
 * The in-process synchronous backend: one frame per attempt, kernel-owned
 * catch at the frame boundary, Errs returned and routed, Faults projected
 * and unwound. Concurrency is a backend property — Swoole backends add it;
 * the contract here is collection and routing semantics.
 */
final class SyncScope implements Scope
{
    private int $sequence = 0;

    /** @var list<callable(): void> */
    private array $compensations = [];

    /** @param list<callable(Fault): (Err|Fault)> $absorbers */
    private function __construct(
        private readonly Wiring $wiring,
        private readonly CancelFlag $flag,
        private readonly ?self $origin,
        private readonly bool $frame,
        private readonly int $attempts,
        private readonly Backoff $backoff,
        private readonly ?Mark $deadline,
        private readonly array $absorbers,
    ) {
    }

    public static function root(Wiring $wiring): self
    {
        return new self($wiring, new CancelFlag(), null, false, 1, Backoff::none(), null, []);
    }

    /**
     * @template TOut
     *
     * @param Executable<TOut> $work
     *
     * @return TOut
     */
    public function run(Executable $work): mixed
    {
        $signature = InvokeSignature::of($work);
        $attempt = Attempt::first();

        while (true) {
            $frameScope = $this->frameScope();
            $ctx = new FrameCtx($this->nextId(), $attempt, $frameScope);

            try {
                $outcome = $this->dispatch($work, $ctx, $frameScope, $signature->capsClass);
            } catch (Throwable $thrown) {
                $fault = $thrown instanceof FaultSignal
                    ? $thrown->fault
                    : Fault::fromThrowable($thrown, $ctx->id, $attempt, $signature->operation);

                $frameScope->compensate();

                $outcome = $this->routeFault($fault);

                break;
            }

            if (!$outcome instanceof Err) {
                break;
            }

            $frameScope->compensate();

            if (!$this->retryGate($outcome, $attempt)) {
                break;
            }

            $delay = $this->backoff->delayFor($attempt->number - 1);

            if ($delay->isPositive()) {
                usleep($delay->toMicroseconds());
            }

            $attempt = $attempt->next();
        }

        /**
         * The kernel trust boundary: outcome membership in TOut is the task
         * author's declared union (the generics keystone); the kernel can
         * only preserve it, not prove it.
         *
         * @var TOut $outcome
         */
        return $outcome;
    }

    public function parallel(array $work): array
    {
        $outcomes = [];
        $pendingFault = null;

        foreach ($work as $unit) {
            try {
                $outcomes[] = $this->run($unit);
            } catch (FaultSignal | FaultEscaped $unwinding) {
                $pendingFault = $unwinding;
            }
        }

        if ($pendingFault !== null) {
            throw $pendingFault;
        }

        return $outcomes;
    }

    public function map(iterable $items, callable $factory): array
    {
        $work = [];

        foreach ($items as $item) {
            $work[] = $factory($item);
        }

        return $this->parallel($work);
    }

    public function race(array $work): mixed
    {
        return $this->run($work[0]);
    }

    /**
     * @template TFirst
     * @template TStep
     * @template TIn = mixed
     *
     * @param Executable<TFirst> $first
     * @param callable(TIn): Executable<TStep> ...$steps
     *
     * @return TFirst|TStep
     */
    public function series(Executable $first, callable ...$steps): mixed
    {
        $outcome = $this->run($first);

        foreach ($steps as $step) {
            if ($outcome instanceof Err) {
                return $outcome;
            }

            /**
             * The kernel trust boundary again: a step factory consumes the
             * prior step's success value; the chain itself is the task
             * author's contract.
             *
             * @var callable(mixed): Executable<TStep> $step
             */
            $outcome = $this->run($step($outcome));
        }

        return $outcome;
    }

    public function onErr(callable $compensation): void
    {
        $this->compensations[] = $compensation;
    }

    public function cancel(): void
    {
        $this->flag->raise();
    }

    public function isCancelled(): bool
    {
        if ($this->flag->isRaised()) {
            return true;
        }

        return $this->deadline !== null && $this->remaining()->isZero();
    }

    public function remaining(): Mark
    {
        if ($this->deadline === null) {
            return Mark::ns(PHP_INT_MAX);
        }

        return Mark::now()->until($this->deadline);
    }

    public function withRetry(int $attempts, Backoff $backoff): Scope
    {
        return new self(
            $this->wiring,
            $this->flag,
            $this->originScope(),
            $this->frame,
            max(1, $attempts),
            $backoff,
            $this->deadline,
            $this->absorbers,
        );
    }

    public function withDeadline(Mark $deadline): Scope
    {
        $absolute = Mark::now()->plus($deadline);

        if ($this->deadline !== null) {
            $absolute = $absolute->min($this->deadline);
        }

        return new self(
            $this->wiring,
            $this->flag,
            $this->originScope(),
            $this->frame,
            $this->attempts,
            $this->backoff,
            $absolute,
            $this->absorbers,
        );
    }

    public function withoutRetry(): Scope
    {
        return new self(
            $this->wiring,
            $this->flag,
            $this->originScope(),
            $this->frame,
            1,
            Backoff::none(),
            $this->deadline,
            $this->absorbers,
        );
    }

    /** @param array<array-key, mixed>|callable(Fault): (Err|Fault|array<array-key, mixed>) $absorb */
    public function faultsAs(array|callable $absorb): Scope
    {
        $absorber = is_array($absorb) ? self::bareMapAbsorber($absorb) : self::deferredAbsorber($absorb);

        return new self(
            $this->wiring,
            $this->flag,
            $this->originScope(),
            $this->frame,
            $this->attempts,
            $this->backoff,
            $this->deadline,
            [...$this->absorbers, $absorber],
        );
    }

    /**
     * A bare map exists eagerly, so its arms are validated eagerly: FQCN
     * values only — an Err instance here would construct on the hot path.
     *
     * @param array<array-key, mixed> $map
     *
     * @return callable(Fault): (Err|Fault)
     */
    private static function bareMapAbsorber(array $map): callable
    {
        foreach ($map as $thrown => $born) {
            if ($born instanceof Err) {
                throw new InvalidArgumentException(sprintf(
                    'faultsAs map arm "%s" is an Err instance; instances construct eagerly - use the fault-built callable map.',
                    $thrown,
                ));
            }

            if (!is_string($born) || !is_a($born, FaultBorn::class, true)) {
                throw new InvalidArgumentException(sprintf(
                    'faultsAs map arm "%s" must map to a FaultBorn class-string.',
                    $thrown,
                ));
            }
        }

        return static fn (Fault $fault): Err|Fault => self::convertThroughMap($map, $fault);
    }

    /**
     * One callable shape, disambiguated by what it returns: an array is a
     * fault-built map (built only on the fault path), anything else is the
     * bare absorber outcome.
     *
     * @param callable(Fault): (Err|Fault|array<array-key, mixed>) $absorb
     *
     * @return callable(Fault): (Err|Fault)
     */
    private static function deferredAbsorber(callable $absorb): callable
    {
        return static function (Fault $fault) use ($absorb): Err|Fault {
            $produced = $absorb($fault);

            return is_array($produced) ? self::convertThroughMap($produced, $fault) : $produced;
        };
    }

    /**
     * Arms match chain-wide lineage in map iteration order, first match
     * wins; an unmatched fault declines and keeps unwinding.
     *
     * @param array<array-key, mixed> $map
     */
    private static function convertThroughMap(array $map, Fault $fault): Err|Fault
    {
        foreach ($map as $thrown => $born) {
            if (!is_string($thrown) || !$fault->is($thrown)) {
                continue;
            }

            if ($born instanceof Err) {
                return $born;
            }

            if (is_string($born) && is_a($born, FaultBorn::class, true)) {
                return $born::fromFault($fault);
            }

            throw new InvalidArgumentException(sprintf(
                'faultsAs map arm "%s" must map to an Err instance or a FaultBorn class-string.',
                $thrown,
            ));
        }

        return $fault;
    }

    /**
     * @param Executable<mixed> $work
     * @param class-string<\Phalanx\Invocation\Caps>|null $capsClass
     */
    private function dispatch(Executable $work, FrameCtx $ctx, self $frameScope, ?string $capsClass): mixed
    {
        if (!is_callable($work)) {
            throw new FaultSignal(Fault::fromThrowable(
                new \LogicException($work::class . ' is not invokable.'),
                $ctx->id,
                $ctx->attempt,
            ));
        }

        return $capsClass === null ? $work($ctx) : $work($ctx, $this->wiring->supply($capsClass, $frameScope));
    }

    /**
     * A fresh frame inherits TIME and CANCELLATION (deadline, flag chain) and
     * the wiring — never the dispatch site's absorbers or retry budget. Those
     * are per-dispatch narrowings; a body re-narrows for its own children.
     */
    private function frameScope(): self
    {
        return new self(
            $this->wiring,
            new CancelFlag($this->flag),
            $this->originScope(),
            true,
            1,
            Backoff::none(),
            $this->deadline,
            [],
        );
    }

    private function compensate(): void
    {
        foreach (array_reverse($this->compensations) as $compensation) {
            $compensation();
        }

        $this->compensations = [];
    }

    private function routeFault(Fault $fault): Err
    {
        foreach (array_reverse($this->absorbers) as $absorb) {
            $result = $absorb($fault);

            if ($result instanceof Err) {
                return $result;
            }

            $fault = $result;
        }

        if ($this->frame) {
            throw new FaultSignal($fault);
        }

        throw new FaultEscaped($fault);
    }

    private function retryGate(Err $err, Attempt $attempt): bool
    {
        if ($attempt->number >= $this->attempts) {
            return false;
        }

        if ($this->deadline !== null && $this->remaining()->isZero()) {
            return false;
        }

        if ($err instanceof Retryable) {
            return $err->retryable;
        }

        return $err->severity === Severity::Transient;
    }

    private function originScope(): self
    {
        return $this->origin ?? $this;
    }

    private function nextId(): InvocationId
    {
        $origin = $this->originScope();

        return InvocationId::of('run-' . ++$origin->sequence);
    }
}
