<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\Support;

use Closure;
use Phalanx\Disposable;
use Phalanx\ExecutionScope;
use Phalanx\Support\ScopedSignalHandler;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use RuntimeException;

final class ScopedSignalDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('signal delivery test is Unix-only');
        }

        if (!function_exists('posix_kill') || !function_exists('posix_getpid') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('posix + pcntl extensions required');
        }
    }

    #[Test]
    public function handler_fires_when_signal_delivered_during_scope_lifetime(): void
    {
        $invocations = 0;

        TestScope::run(static function (ExecutionScope $scope) use (&$invocations): void {
            $deferred = new Deferred();

            $handler = static function () use (&$invocations, $deferred): void {
                $invocations++;
                $deferred->resolve(null);
            };

            ScopedSignalHandler::on($scope, \SIGUSR1, $handler);

            Loop::futureTick(static function (): void {
                posix_kill(posix_getpid(), \SIGUSR1);
            });

            $watchdog = Loop::addTimer(2.0, static function () use ($deferred): void {
                $deferred->reject(new RuntimeException('signal delivery timed out after 2s'));
            });

            try {
                $scope->await($deferred->promise());
            } finally {
                Loop::cancelTimer($watchdog);
            }
        });

        self::assertSame(1, $invocations, 'handler should fire exactly once on signal delivery');
    }

    #[Test]
    public function handler_calling_scope_dispose_does_not_produce_fiber_error(): void
    {
        $disposeCalled = false;

        TestScope::run(static function (ExecutionScope $scope) use (&$disposeCalled): void {
            $sentinel = new Deferred();

            $handler = static function () use ($scope, &$disposeCalled, $sentinel): void {
                $disposeCalled = true;
                $sentinel->resolve(null);
                $scope->dispose();
            };

            ScopedSignalHandler::on($scope, \SIGUSR1, $handler);

            Loop::futureTick(static function (): void {
                posix_kill(posix_getpid(), \SIGUSR1);
            });

            $watchdog = Loop::addTimer(2.0, static function () use ($sentinel): void {
                $sentinel->reject(new RuntimeException('handler did not fire within 2s'));
            });

            try {
                $scope->await($sentinel->promise());
            } finally {
                Loop::cancelTimer($watchdog);
            }
        });

        self::assertTrue($disposeCalled, 'handler that calls $scope->dispose() must run cleanly without FiberError');
    }

    #[Test]
    public function handler_does_not_fire_after_scope_dispose(): void
    {
        $invocations = 0;
        $observed = false;

        $disposed = new RecordingDisposable();
        ScopedSignalHandler::on($disposed, \SIGUSR1, static function () use (&$invocations): void {
            $invocations++;
        });
        $disposed->dispose();

        TestScope::run(static function (ExecutionScope $scope) use (&$observed): void {
            $sentinel = new Deferred();
            $observer = static function () use (&$observed, $sentinel): void {
                $observed = true;
                $sentinel->resolve(null);
            };

            ScopedSignalHandler::on($scope, \SIGUSR1, $observer);

            Loop::futureTick(static function (): void {
                posix_kill(posix_getpid(), \SIGUSR1);
            });

            $watchdog = Loop::addTimer(2.0, static function () use ($sentinel): void {
                $sentinel->reject(new RuntimeException('observer never fired — signal delivery broken'));
            });

            try {
                $scope->await($sentinel->promise());
            } finally {
                Loop::cancelTimer($watchdog);
            }
        });

        self::assertTrue($observed, 'observer must see the signal — confirms the signal actually fired');
        self::assertSame(0, $invocations, 'disposed scope handler must NOT fire');
    }

    #[Test]
    public function thousand_register_dispose_cycles_complete_without_leak(): void
    {
        $loop = Loop::get();
        $sentinel = static fn() => null;
        $loop->addSignal(\SIGUSR1, $sentinel);
        $baseline = self::countSignalListeners(\SIGUSR1);
        $loop->removeSignal(\SIGUSR1, $sentinel);

        self::assertSame(0, $baseline - 1, 'expected only the sentinel registered before the loop');

        $startMem = memory_get_usage();

        for ($i = 0; $i < 1000; $i++) {
            $scope = new RecordingDisposable();
            ScopedSignalHandler::on($scope, \SIGUSR1, static fn() => null);
            $scope->dispose();
        }

        gc_collect_cycles();
        $delta = memory_get_usage() - $startMem;

        self::assertLessThan(
            500_000,
            $delta,
            "memory grew by {$delta} bytes after 1000 register/dispose cycles — possible leak",
        );

        self::assertSame(
            0,
            self::countSignalListeners(\SIGUSR1),
            'no Loop signal listeners should remain after 1000 register+dispose cycles',
        );
    }

    private static function countSignalListeners(int $signal): int
    {
        $listeners = self::signalListenersFor($signal);

        return $listeners === null ? 0 : count($listeners);
    }

    /**
     * @return list<Closure>|null
     */
    private static function signalListenersFor(int $signal): ?array
    {
        $loop = Loop::get();
        $signalsHandler = self::extractProperty($loop, 'signals');

        if ($signalsHandler === null) {
            return null;
        }

        $signals = self::extractProperty($signalsHandler, 'signals');

        if (!is_array($signals)) {
            return null;
        }

        $listeners = $signals[$signal] ?? null;

        return is_array($listeners) ? array_values($listeners) : null;
    }

    private static function extractProperty(object $instance, string $name): mixed
    {
        try {
            $reflection = new \ReflectionObject($instance);

            if (!$reflection->hasProperty($name)) {
                return null;
            }

            $property = $reflection->getProperty($name);

            return $property->getValue($instance);
        } catch (\Throwable) {
            return null;
        }
    }
}

final class RecordingDisposable implements Disposable
{
    /** @var list<Closure> */
    public private(set) array $callbacks = [];

    public function onDispose(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function dispose(): void
    {
        foreach ($this->callbacks as $callback) {
            $callback();
        }

        $this->callbacks = [];
    }
}
