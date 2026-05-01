<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Support;

use Closure;
use InvalidArgumentException;
use Phalanx\Disposable;
use Phalanx\Support\ScopedSignalHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class ScopedSignalHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix-only contract tests; Windows path is exercised via platform-specific suite.');
        }

        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl extension required.');
        }
    }

    #[Test]
    public function on_with_single_int_registers_handler_and_disposes_cleanly(): void
    {
        $scope = new RecordingDisposable();
        $handler = static fn() => null;

        $countBefore = self::countSignalListeners(\SIGUSR1);

        ScopedSignalHandler::on($scope, \SIGUSR1, $handler);

        self::assertSame($countBefore + 1, self::countSignalListeners(\SIGUSR1));
        self::assertCount(1, $scope->callbacks, 'expected one onDispose callback registered');

        $scope->dispose();

        self::assertSame($countBefore, self::countSignalListeners(\SIGUSR1));
    }

    #[Test]
    public function on_with_array_of_signals_registers_each_independently(): void
    {
        $scope = new RecordingDisposable();
        $handler = static fn() => null;

        $usr1Before = self::countSignalListeners(\SIGUSR1);
        $usr2Before = self::countSignalListeners(\SIGUSR2);

        ScopedSignalHandler::on($scope, [\SIGUSR1, \SIGUSR2], $handler);

        self::assertSame($usr1Before + 1, self::countSignalListeners(\SIGUSR1));
        self::assertSame($usr2Before + 1, self::countSignalListeners(\SIGUSR2));

        $scope->dispose();

        self::assertSame($usr1Before, self::countSignalListeners(\SIGUSR1));
        self::assertSame($usr2Before, self::countSignalListeners(\SIGUSR2));
    }

    #[Test]
    public function multiple_scopes_registering_same_signal_compose_and_dispose_independently(): void
    {
        $scopeA = new RecordingDisposable();
        $scopeB = new RecordingDisposable();
        $handlerA = static fn() => null;
        $handlerB = static fn() => null;

        $before = self::countSignalListeners(\SIGUSR1);

        ScopedSignalHandler::on($scopeA, \SIGUSR1, $handlerA);
        ScopedSignalHandler::on($scopeB, \SIGUSR1, $handlerB);

        self::assertSame($before + 2, self::countSignalListeners(\SIGUSR1));

        $scopeA->dispose();
        self::assertSame($before + 1, self::countSignalListeners(\SIGUSR1), 'B handler must remain');

        $scopeB->dispose();
        self::assertSame($before, self::countSignalListeners(\SIGUSR1));
    }

    #[Test]
    public function duplicate_signals_in_array_are_collapsed(): void
    {
        $scope = new RecordingDisposable();
        $handler = static fn() => null;

        $before = self::countSignalListeners(\SIGUSR1);

        ScopedSignalHandler::on($scope, [\SIGUSR1, \SIGUSR1, \SIGUSR1], $handler);

        self::assertSame($before + 1, self::countSignalListeners(\SIGUSR1));

        $scope->dispose();
        self::assertSame($before, self::countSignalListeners(\SIGUSR1));
    }

    #[Test]
    public function empty_array_is_silent_noop(): void
    {
        $scope = new RecordingDisposable();

        ScopedSignalHandler::on($scope, [], static fn() => null);

        self::assertSame([], $scope->callbacks, 'no onDispose callback should be registered when nothing was bound');
    }

    #[Test]
    public function non_static_closure_throws_invalid_argument(): void
    {
        $scope = new RecordingDisposable();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('static closure');

        ScopedSignalHandler::on($scope, \SIGUSR1, function (): void {});
    }

    private static function countSignalListeners(int $signal): int
    {
        $listeners = self::signalListenersFor(Loop::get(), $signal);

        return $listeners === null ? 0 : count($listeners);
    }

    /**
     * @return list<Closure>|null
     */
    private static function signalListenersFor(object $loop, int $signal): ?array
    {
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
