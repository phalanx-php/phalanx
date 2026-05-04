<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Diagnostics;

use Phalanx\Diagnostics\PressureMonitor;
use Phalanx\Server\ServerStats;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\TestCase;

/**
 * PressureMonitor::sample() emits PHX-PRESSURE-001 when the snapshot's
 * event_loop_lag_ms crosses the threshold. The periodic loop is exercised
 * via integration tests with a real server; this unit test drives the
 * sample() entry directly using a static stats provider.
 */
final class PressureMonitorTest extends TestCase
{
    public function testNoEventEmittedBelowThreshold(): void
    {
        $trace = new Trace();
        $stats = ServerStats::fromArray(['event_loop_lag' => 10_000]); // 10 ms
        $monitor = new PressureMonitor($trace, $stats, thresholdMs: 50.0);

        $monitor->sample();

        $diagnostics = self::diagnosticEvents($trace);
        self::assertCount(0, $diagnostics);
    }

    public function testEventEmittedAboveThreshold(): void
    {
        $trace = new Trace();
        $stats = ServerStats::fromArray(['event_loop_lag' => 75_000]); // 75 ms
        $monitor = new PressureMonitor($trace, $stats, thresholdMs: 50.0);

        $lag = $monitor->sample();

        self::assertSame(75.0, $lag);
        $diagnostics = self::diagnosticEvents($trace);
        self::assertCount(1, $diagnostics);
        self::assertSame('PHX-PRESSURE-001', $diagnostics[0]->name);
        self::assertSame(75.0, $diagnostics[0]->attrs['lag_ms']);
        self::assertSame(50.0, $diagnostics[0]->attrs['threshold_ms']);
    }

    public function testEventCarriesConnectionAndCoroutineCounters(): void
    {
        $trace = new Trace();
        $stats = ServerStats::fromArray([
            'event_loop_lag' => 100_000,
            'connection_num' => 250,
            'coroutine_num' => 480,
        ]);
        $monitor = new PressureMonitor($trace, $stats, thresholdMs: 50.0);

        $monitor->sample();

        $event = self::diagnosticEvents($trace)[0];
        self::assertSame(250, $event->attrs['connection_num']);
        self::assertSame(480, $event->attrs['coroutine_num']);
    }

    /**
     * @return list<\Phalanx\Trace\TraceEvent>
     */
    private static function diagnosticEvents(Trace $trace): array
    {
        $events = [];
        foreach ($trace->events() as $event) {
            if ($event->type === TraceType::Lifecycle && $event->name === PressureMonitor::DIAGNOSTIC_CODE) {
                $events[] = $event;
            }
        }
        return $events;
    }
}
