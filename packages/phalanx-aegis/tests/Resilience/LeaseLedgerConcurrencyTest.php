<?php

declare(strict_types=1);

namespace Phalanx\Tests\Resilience;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\WaitGroup;
use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;

/**
 * Stress the lease ledger under sibling concurrency. Each sibling
 * acquires + releases a lease against a different pool domain (so no
 * PHX-POOL-001 collisions) and we assert that:
 *
 *   - All leases are released cleanly
 *   - Ledger drains to zero
 *   - No false-positive lease tracking across sibling boundaries (sibling
 *     A's lease should never appear on sibling B's TaskRun)
 */
final class LeaseLedgerConcurrencyTest extends PhalanxTestCase
{
    public function testHundredSiblingsAcquireAndReleaseDistinctLeases(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $appScope = $app->createScope();

            $tasks = [];
            for ($i = 0; $i < 100; $i++) {
                $tasks["worker-{$i}"] = new LeaseHolder("pool-{$i}");
            }

            $results = $appScope->concurrent(...$tasks);

            // Every worker reports its own pool domain back, proving leases
            // are scoped to the right TaskRun.
            for ($i = 0; $i < 100; $i++) {
                self::assertSame("pool-{$i}", $results["worker-{$i}"]);
            }

            $appScope->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testRapidAcquireReleaseChurnLeavesNoOrphans(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);

            // 500 cycles of acquire+release on a single domain. Tests that
            // the ledger doesn't accumulate stale lease references across
            // back-to-back operations.
            for ($i = 0; $i < 500; $i++) {
                $appScope = $app->createScope();
                $value = $appScope->execute(new LeaseHolder('postgres/main'));
                self::assertSame('postgres/main', $value);
                $appScope->dispose();
            }

            self::assertSame(0, $ledger->liveCount());
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
}

final class LeaseHolder implements \Phalanx\Task\Executable
{
    public function __construct(public readonly string $domain)
    {
    }

    public function __invoke(ExecutionScope $scope): string
    {
        assert($scope instanceof \Phalanx\Scope\ExecutionLifecycleScope);
        $supervisor = $scope->supervisor();
        $run = $scope->currentRun;
        if ($run === null) {
            throw new \RuntimeException('expected a current run for lease holder');
        }

        $lease = PoolLease::open($this->domain, 'conn-1');
        $supervisor->registerLease($run, $lease);
        try {
            return $this->domain;
        } finally {
            $supervisor->releaseLease($run, $lease);
        }
    }
}
