<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

use Closure;
use Phalanx\Scope\Subscription;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Server\ServerStats;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;

/**
 * Periodic event-loop-lag watchdog.
 *
 * OpenSwoole 26 exposes `event_loop_lag` (microseconds since the reactor
 * last drained the ready queue) on `$server->stats()`. Sustained lag is
 * the canonical signal that some PHP code is blocking the reactor —
 * unhooked I/O, tight CPU loops, or something stuck in a non-yielding
 * extension call.
 *
 * The monitor reads ServerStats on each tick; when lag crosses the
 * threshold it emits PHX-PRESSURE-001 to the Trace, attributed with the
 * current lag value and the configured threshold. Operators consume the
 * trace via the supervisor's diagnostic surface, /metrics endpoint, or
 * any other Trace-listening surface.
 *
 * Lifecycle: `start()` schedules the periodic via the caller's
 * TaskExecutor and returns a Subscription. The subscription is attached
 * to the scope's onDispose stack by `periodic()`, so monitor teardown is
 * automatic when the owning scope ends.
 */
final class PressureMonitor
{
    public const string DIAGNOSTIC_CODE = 'PHX-PRESSURE-001';

    public function __construct(
        private readonly Trace $trace,
        private readonly ServerStats $stats,
        private readonly float $thresholdMs = 50.0,
        private readonly float $intervalSec = 1.0,
    ) {
    }

    public function start(TaskExecutor $scope): Subscription
    {
        $check = $this->makeCheckClosure();
        return $scope->periodic($this->intervalSec, $check);
    }

    /**
     * Run a single sample. Public so consumers can drive a check on demand
     * (e.g. before an admit decision in a load shedder) without waiting
     * for the periodic tick. Returns the sampled lag for caller-side
     * observation.
     */
    public function sample(): float
    {
        $snapshot = $this->stats->snapshot();
        if ($snapshot->eventLoopLagMs > $this->thresholdMs) {
            $this->trace->log(
                TraceType::Lifecycle,
                self::DIAGNOSTIC_CODE,
                [
                    'lag_ms' => $snapshot->eventLoopLagMs,
                    'threshold_ms' => $this->thresholdMs,
                    'connection_num' => $snapshot->connectionNum,
                    'coroutine_num' => $snapshot->coroutineNum,
                    'detail' => 'event-loop lag exceeded threshold; investigate blocking work',
                ],
            );
        }

        return $snapshot->eventLoopLagMs;
    }

    private function makeCheckClosure(): Closure
    {
        $self = $this;
        return static function () use ($self): void {
            $self->sample();
        };
    }
}
