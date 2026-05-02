<?php

declare(strict_types=1);

namespace Phalanx\Tests\Support;

use Closure;
use OpenSwoole\Coroutine;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Test base for cases that exercise suspending primitives — Co::sleep,
 * $scope->call(), $scope->concurrent(), $scope->retry(), worker dispatch,
 * etc. Wraps the test body in Coroutine::run() so HOOK_ALL is active and
 * coroutine-only APIs are callable.
 *
 * Use plain TestCase for tests that don't suspend (data structures,
 * value objects, ledger CRUD).
 */
abstract class CoroutineTestCase extends TestCase
{
    protected static function bootCoroutineRuntime(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        $booted = true;
    }

    protected function runInCoroutine(Closure $body): void
    {
        self::bootCoroutineRuntime();

        $caught = null;
        $finished = false;

        Coroutine::run(static function () use ($body, &$caught, &$finished): void {
            try {
                $body();
            } catch (Throwable $e) {
                $caught = $e;
            } finally {
                $finished = true;
            }
        });

        if ($caught !== null) {
            throw $caught;
        }
        if (!$finished) {
            self::fail('Coroutine body did not run to completion');
        }
    }

    /**
     * @param Closure(ExecutionScope): void $test
     * @param Closure|null $services
     * @param array<string, mixed> $context
     */
    protected function runScoped(
        Closure $test,
        ?Closure $services = null,
        array $context = [],
    ): void {
        $this->runScopedWithLedger(new InProcessLedger(), $test, $services, $context);
    }

    /**
     * @param Closure(ExecutionScope): void $test
     * @param Closure|null $services
     * @param array<string, mixed> $context
     */
    protected function runScopedWithLedger(
        InProcessLedger $ledger,
        Closure $test,
        ?Closure $services = null,
        array $context = [],
    ): void {
        $this->runInCoroutine(static function () use ($ledger, $test, $services, $context): void {
            TestScope::compile($services, $context, $ledger)
                ->shutdownAfterRun()
                ->run($test);
        });
    }
}
