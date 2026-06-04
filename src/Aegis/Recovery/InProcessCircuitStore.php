<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;
use Throwable;

final class InProcessCircuitStore implements CircuitStore
{
    /** @var array<string, CircuitStateRecord> */
    private array $circuits = [];

    public function beforeAttempt(Circuit $circuit): CircuitSnapshot
    {
        $record = $this->resolve($circuit);

        if ($record->state === CircuitState::Open) {
            if ($circuit->cooldown !== null && $record->openedAt !== null) {
                $elapsed = $record->openedAt->elapsed();

                if ($elapsed->gte($circuit->cooldown)) {
                    $record->state = CircuitState::HalfOpen;
                    $record->halfOpenedAt = Mark::now();
                    $record->activeProbes = 0;
                }
            }
        }

        if ($record->state === CircuitState::HalfOpen) {
            $record->activeProbes++;
        }

        return $this->snapshot($circuit->key, $record);
    }

    public function recordSuccess(Circuit $circuit): void
    {
        $record = $this->resolve($circuit);

        $record->failureCount = 0;
        $record->state = CircuitState::Closed;
        $record->openedAt = null;
        $record->halfOpenedAt = null;
        $record->activeProbes = 0;
    }

    public function recordFailure(Circuit $circuit, Throwable $error): void
    {
        $record = $this->resolve($circuit);
        $record->failureCount++;

        if ($record->state === CircuitState::HalfOpen) {
            $record->state = CircuitState::Open;
            $record->openedAt = Mark::now();
            $record->halfOpenedAt = null;
            $record->activeProbes = 0;

            return;
        }

        if ($record->windowStart === null) {
            $record->windowStart = Mark::now();
        }

        if ($record->state === CircuitState::Closed) {
            $shouldTrip = $record->failureCount >= $circuit->failureThreshold;

            if ($shouldTrip && $circuit->failureWindow !== null) {
                if ($record->windowStart->elapsed()->gt($circuit->failureWindow)) {
                    $record->failureCount = 1;
                    $record->windowStart = Mark::now();
                    $shouldTrip = false;
                }
            }

            if ($shouldTrip) {
                $record->state = CircuitState::Open;
                $record->openedAt = Mark::now();
                $record->failureCount = 0;
                $record->windowStart = null;
            }
        }
    }

    private function resolve(Circuit $circuit): CircuitStateRecord
    {
        $key = $circuit->key->value;

        return $this->circuits[$key] ??= new CircuitStateRecord();
    }

    private function snapshot(CircuitKey $key, CircuitStateRecord $record): CircuitSnapshot
    {
        return new CircuitSnapshot(
            key: $key,
            state: $record->state,
            failureCount: $record->failureCount,
            openedAt: $record->openedAt,
            halfOpenedAt: $record->halfOpenedAt,
            activeProbes: $record->activeProbes,
        );
    }
}

/**
 * @internal
 */
final class CircuitStateRecord
{
    public CircuitState $state = CircuitState::Closed;

    public int $failureCount = 0;

    public ?Mark $openedAt = null;

    public ?Mark $halfOpenedAt = null;

    public int $activeProbes = 0;

    public ?Mark $windowStart = null;
}
