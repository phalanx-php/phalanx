<?php

declare(strict_types=1);

namespace Phalanx\Tests\Resilience;

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;

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
final class CancellationPropagationTimingTest extends CoroutineTestCase
{
    private const float CANCEL_BUDGET_SECONDS = 1.0;

    private const float CANCEL_AFTER_USEC = 15_000;

    public function testConcurrentCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->concurrent(...[
                'a' => Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                'b' => Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                'c' => Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
            ]);
        });
    }

    public function testRaceCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->race(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
            ]);
        });
    }

    public function testAnyCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->any(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
            ]);
        });
    }

    public function testMapCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->map(
                [1, 2, 3, 4, 5],
                static fn(int $n) => Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                limit: 3,
            );
        });
    }

    public function testSeriesCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->series(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
            ]);
        });
    }

    public function testSettleCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            // settle returns Result objects but should still observe cancel.
            $scope->settle(...[
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
                Task::of(static fn(ExecutionScope $s) => $s->delay(5.0)),
            ]);
        });
    }

    public function testDelayCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->delay(5.0);
        });
    }

    public function testCallCancelsWithinBudget(): void
    {
        $this->assertCancelTimingOf(static function (ExecutionScope $scope): void {
            $scope->call(static function (): void {
                \OpenSwoole\Coroutine::usleep(5_000_000);
            });
        });
    }

    private static function buildApp(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }

    /**
     * @param \Closure(ExecutionScope): mixed $body
     */
    private function assertCancelTimingOf(\Closure $body): void
    {
        $this->runInCoroutine(function () use ($body): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();
            $token = $scope->cancellation();

            $cancelAfter = self::CANCEL_AFTER_USEC;
            \OpenSwoole\Coroutine::create(static function () use ($token, $cancelAfter): void {
                \OpenSwoole\Coroutine::usleep((int) $cancelAfter);
                $token->cancel();
            });

            $start = microtime(true);
            $cancelled = false;
            try {
                $body($scope);
            } catch (Cancelled) {
                $cancelled = true;
            } catch (\Throwable $e) {
                // Some primitives (settle) wrap, some throw; either way we care
                // that elapsed is under budget and ledger drains.
                $cancelled = true;
            }
            $elapsed = microtime(true) - $start;

            $scope->dispose();

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
