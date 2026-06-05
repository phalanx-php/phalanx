<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;

final class RecoveryPlan
{
    /** @var list<class-string<\Throwable>> */
    private array $retryOn = [];

    private ?Closure $eventCallback = null;

    private ?Circuit $circuit = null;

    private function __construct(
        private(set) ?int $attempts,
        private(set) ?Mark $attemptTimeout,
        private(set) ?Mark $deadline,
        private(set) ?Backoff $backoff,
        private(set) ?Jitter $jitter,
        private(set) ?Mark $pollInterval,
        private(set) ?Mark $checkpointEvery,
    ) {
    }

    public static function none(): self
    {
        return new self(
            attempts: null,
            attemptTimeout: null,
            deadline: null,
            backoff: null,
            jitter: null,
            pollInterval: null,
            checkpointEvery: null,
        );
    }

    public static function failFast(?Mark $deadline = null): self
    {
        return new self(
            attempts: 1,
            attemptTimeout: null,
            deadline: $deadline,
            backoff: null,
            jitter: null,
            pollInterval: null,
            checkpointEvery: null,
        );
    }

    /**
     * @param list<class-string<\Throwable>> $retryOn
     */
    public static function defaultRetry(
        int $attempts = 3,
        ?Mark $attemptTimeout = null,
        ?Mark $deadline = null,
        ?Backoff $backoff = null,
        ?Jitter $jitter = null,
        array $retryOn = [],
    ): self {
        $plan = new self(
            attempts: $attempts,
            attemptTimeout: $attemptTimeout,
            deadline: $deadline,
            backoff: $backoff ?? Backoff::exponential(Mark::ms(100), Mark::s(30)),
            jitter: $jitter ?? Jitter::percent(10),
            pollInterval: null,
            checkpointEvery: null,
        );

        $plan->retryOn = array_values($retryOn);

        return $plan;
    }

    /**
     * @param list<class-string<\Throwable>> $retryOn
     */
    public static function polling(
        Mark $interval,
        ?Mark $deadline = null,
        array $retryOn = [],
    ): self {
        $plan = new self(
            attempts: null,
            attemptTimeout: null,
            deadline: $deadline,
            backoff: null,
            jitter: null,
            pollInterval: $interval,
            checkpointEvery: null,
        );

        $plan->retryOn = array_values($retryOn);

        return $plan;
    }

    public static function longRunning(
        ?Mark $deadline = null,
        ?Mark $checkpointEvery = null,
    ): self {
        return new self(
            attempts: 1,
            attemptTimeout: null,
            deadline: $deadline,
            backoff: null,
            jitter: null,
            pollInterval: null,
            checkpointEvery: $checkpointEvery,
        );
    }

    public function withAttemptTimeout(Mark $time): self
    {
        $clone = clone $this;
        $clone->attemptTimeout = $time;

        return $clone;
    }

    public function withDeadline(Mark $time): self
    {
        $clone = clone $this;
        $clone->deadline = $time;

        return $clone;
    }

    public function withBackoff(Backoff $backoff): self
    {
        $clone = clone $this;
        $clone->backoff = $backoff;

        return $clone;
    }

    /** @param class-string<\Throwable> ...$throwables */
    public function retryingOn(string ...$throwables): self
    {
        $clone = clone $this;
        $clone->retryOn = array_values($throwables);

        return $clone;
    }

    public function onEvent(Closure $callback): self
    {
        $clone = clone $this;
        $clone->eventCallback = $callback;

        return $clone;
    }

    public function circuit(Circuit $circuit): self
    {
        $clone = clone $this;
        $clone->circuit = $circuit;

        return $clone;
    }

    public function shouldRetry(\Throwable $e): bool
    {
        if ($e instanceof Cancelled) {
            return false;
        }

        if ($this->retryOn === []) {
            return true;
        }

        return array_any($this->retryOn, static fn(string $type): bool => $e instanceof $type);
    }

    public function effectiveBackoff(): ?Backoff
    {
        if ($this->backoff === null) {
            return null;
        }

        if ($this->jitter === null) {
            return $this->backoff;
        }

        return $this->backoff->withJitter($this->jitter);
    }

    /** @return list<class-string<\Throwable>> */
    public function retryOnTypes(): array
    {
        return $this->retryOn;
    }

    public function eventCallback(): ?Closure
    {
        return $this->eventCallback;
    }

    public function circuitConfig(): ?Circuit
    {
        return $this->circuit;
    }

    public function isNone(): bool
    {
        return $this->attempts === null
            && $this->deadline === null
            && $this->pollInterval === null;
    }
}
