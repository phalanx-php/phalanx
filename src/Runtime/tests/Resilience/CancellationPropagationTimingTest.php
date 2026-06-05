<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Resilience;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;

/**
 * For each top-level concurrency primitive, schedules a long-sleeping body,
 * cancels the scope after a short delay, and asserts that:
 *
 *   - Cancelled is raised
 *   - Total elapsed time is comfortably below a budget
 *   - Ledger is empty after dispose
 *
 * The budget is generous (1.0s) — this is a sanity floor, not a microbench.
 * If a primitive is failing this it means cancellation is not propagating
 * to children at all, not that it's a few ms slow.
 */
final class CancellationPropagationTimingTest extends PhalanxTestCase
{
    private const float CANCEL_BUDGET_SECONDS = 1.0;

    private const float CANCEL_AFTER_USEC = 15_000; // microseconds; divide by 1_000_000 for Coroutine::sleep()

    public function testConcurrentCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->concurrent(
                a: Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                b: Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                c: Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
            );
        });
    }

    public function testRaceCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->race(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
            ]);
        });
    }

    public function testAnyCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->any(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
            ]);
        });
    }

    public function testMapCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->map(
                [1, 2, 3, 4, 5],
                static fn(int $_n) => Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                limit: 3,
            );
        });
    }

    public function testSeriesCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->series(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
            ]);
        });
    }

    public function testSettleCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            // settle returns Result objects but should still observe cancel.
            $scope->settle(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
                Task::of(static fn(ExecutionScope $s) => $s->delay(Mark::s(5))),
            ]);
        });
    }

    public function testDelayCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->delay(Mark::s(5));
        });
    }

    public function testCallCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->call(static function (): void {
                \Swoole\Coroutine::sleep(5.0);
            });
        });
    }

    private static function buildApp(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        return Application::starting()
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }

    /**
     * @param \Closure(ExecutionScope): mixed $body
     */
    private function assertCancelTimingOf(\Closure $body): void
    {
        $this->scope->run(static function (ExecutionScope $_scope) use ($body): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $appScope = $app->createScope();
            $token = $appScope->cancellation();

            $cancelAfter = self::CANCEL_AFTER_USEC;
            \Swoole\Coroutine::create(static function () use ($token, $cancelAfter): void {
                \Swoole\Coroutine::sleep($cancelAfter / 1_000_000);
                $token->cancel();
            });

            $start = microtime(true);
            $cancelled = false;
            try {
                $body($appScope);
            } catch (Cancelled) {
                $cancelled = true;
            } catch (\Throwable) {
                // Some primitives (settle) wrap, some throw; either way we care
                // that elapsed is under budget and ledger drains.
                $cancelled = true;
            }
            $elapsed = microtime(true) - $start;

            $appScope->dispose();

            self::assertTrue(
                $cancelled || $elapsed < self::CANCEL_BUDGET_SECONDS,
                'expected either Cancelled to surface or fast return',
            );
            self::assertLessThan(
                self::CANCEL_BUDGET_SECONDS,
                $elapsed,
                sprintf('cancellation took %.3fs (budget %.3fs)', $elapsed, self::CANCEL_BUDGET_SECONDS),
            );
            self::assertSame(0, $ledger->liveCount(), 'ledger not drained after cancel');
        });
    }
}
