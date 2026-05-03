<?php

declare(strict_types=1);

namespace Phalanx\Tests\Resilience;

use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;

/**
 * Drives many execute / cancel cycles through one Application + one ledger
 * and asserts that:
 *
 *   - Ledger live count returns to zero after each batch
 *   - Ledger live count is zero at the end
 *   - PHP memory growth is bounded (slack budget set generously to absorb
 *     PHPUnit / OpenSwoole ambient noise on different machines)
 *
 * This is the tripwire that catches reference-cycle regressions in the
 * supervisor / scope / task path before they become a long-running-process
 * problem in production.
 */
final class LedgerChurnTest extends CoroutineTestCase
{
    public function testTenThousandExecuteCyclesLeaveLedgerEmpty(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);

            $iterations = 10_000;
            $batchSize = 1_000;

            $beforeMem = memory_get_usage();

            for ($batch = 0; $batch < $iterations / $batchSize; $batch++) {
                for ($i = 0; $i < $batchSize; $i++) {
                    $scope = $app->createScope();
                    $value = $scope->execute(Task::of(static fn(): int => 42));
                    self::assertSame(42, $value);
                    $scope->dispose();
                }
                self::assertSame(0, $ledger->liveCount(), "ledger empty after batch {$batch}");
            }

            self::assertSame(0, $ledger->liveCount());

            // Memory budget. 10k cycles routinely fit in well under 4MB on
            // dev hardware; pad to 16MB so test isn't flaky on first
            // PHPUnit warmup / OpenSwoole timer churn.
            $afterMem = memory_get_usage();
            $delta = $afterMem - $beforeMem;
            self::assertLessThan(16 * 1024 * 1024, $delta, sprintf(
                'memory grew by %d bytes (%.2f MB) over %d cycles — likely a leak',
                $delta,
                $delta / 1024 / 1024,
                $iterations,
            ));
        });
    }

    public function testConcurrentBatchesLeaveLedgerEmpty(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);

            $iterations = 200;

            for ($i = 0; $i < $iterations; $i++) {
                $scope = $app->createScope();
                $results = $scope->concurrent(...[
                    'a' => Task::of(static fn(ExecutionScope $s): int => 1),
                    'b' => Task::of(static fn(ExecutionScope $s): int => 2),
                    'c' => Task::of(static fn(ExecutionScope $s): int => 3),
                ]);
                self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $results);
                $scope->dispose();
                self::assertSame(0, $ledger->liveCount(), "iteration {$i}");
            }
        });
    }

    private static function buildApp(InProcessLedger $ledger): Application
    {
        $bundle = new class implements ServiceBundle {
            public function services(Services $services, array $context): void
            {
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }
}
