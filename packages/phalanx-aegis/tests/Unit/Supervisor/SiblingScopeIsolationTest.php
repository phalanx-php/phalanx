<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;
use RuntimeException;

/**
 * Verifies the Pool & Scope Discipline #2 invariant: sibling task scopes
 * give each child its own scoped-instance map (so request-scoped state
 * doesn't bleed between siblings) but share the singleton container so
 * pool depth is NOT amplified by per-child scopes.
 */
final class SiblingScopeIsolationTest extends CoroutineTestCase
{
    public function testConcurrentChildrenGetSeparateScopedInstances(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = $this->buildAppWithScopedCounter($ledger);

            /** @var array<string, int> $observed */
            $observed = [];
            $task1 = Task::of(static function (ExecutionScope $s) use (&$observed): int {
                $counter = $s->service(\Phalanx\Tests\Unit\Supervisor\Counter::class);
                $counter->n++;
                $observed['a'] = spl_object_id($counter);
                return $counter->n;
            });
            $task2 = Task::of(static function (ExecutionScope $s) use (&$observed): int {
                $counter = $s->service(\Phalanx\Tests\Unit\Supervisor\Counter::class);
                $counter->n += 100;
                $observed['b'] = spl_object_id($counter);
                return $counter->n;
            });

            $scope = $app->createScope();
            $results = $scope->concurrent(...[
                'a' => $task1,
                'b' => $task2,
            ]);
            $scope->dispose();

            // Each child has its own scoped Counter — different object ids.
            self::assertNotSame($observed['a'], $observed['b']);
            // Each counter started fresh — neither sees the other's mutations.
            self::assertSame(1, $results['a']);
            self::assertSame(100, $results['b']);
        });
    }

    public function testConcurrentChildrenShareSingletonInstances(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = $this->buildAppWithSingletonPool($ledger);

            $observed = [];
            $task1 = Task::of(static function (ExecutionScope $s) use (&$observed): void {
                $pool = $s->service(\Phalanx\Tests\Unit\Supervisor\PoolStub::class);
                $observed['a'] = spl_object_id($pool);
            });
            $task2 = Task::of(static function (ExecutionScope $s) use (&$observed): void {
                $pool = $s->service(\Phalanx\Tests\Unit\Supervisor\PoolStub::class);
                $observed['b'] = spl_object_id($pool);
            });

            $scope = $app->createScope();
            $scope->concurrent(...['a' => $task1, 'b' => $task2]);
            $scope->dispose();

            // Same singleton object — pool depth is not amplified.
            self::assertSame($observed['a'], $observed['b']);
        });
    }

    public function testParentCancellationInterruptsChildren(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = $this->buildAppWithScopedCounter($ledger);

            $reachedAfterDelay = false;
            $task = Task::of(static function (ExecutionScope $s) use (&$reachedAfterDelay): never {
                // Block long enough that natural completion is impossible.
                $s->delay(5.0);
                $reachedAfterDelay = true;
                throw new RuntimeException('should not reach');
            });

            $scope = $app->createScope();
            $parentToken = $scope->cancellation();
            \OpenSwoole\Coroutine::create(static function () use ($parentToken): void {
                \OpenSwoole\Coroutine::usleep(20_000);
                $parentToken->cancel();
            });

            $start = microtime(true);
            $caught = null;
            try {
                $scope->concurrent(...['only' => $task]);
            } catch (Cancelled $e) {
                $caught = $e;
            } finally {
                $scope->dispose();
            }
            $elapsed = microtime(true) - $start;

            // Body interrupted well before the 5s delay would elapse.
            self::assertNotNull($caught, 'concurrent surfaces Cancelled');
            self::assertLessThan(1.0, $elapsed, 'cancel propagated quickly');
            self::assertFalse($reachedAfterDelay, 'body never reached past delay()');
        });
    }

    public function testRaceCancelsLosersThroughTheirOwnTokens(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = $this->buildAppWithScopedCounter($ledger);

            $loserCancelled = false;
            $winner = Task::of(static function (ExecutionScope $s): string {
                $s->delay(0.020);
                return 'winner';
            });
            $loser = Task::of(static function (ExecutionScope $s) use (&$loserCancelled): never {
                $s->cancellation()->onCancel(static function () use (&$loserCancelled): void {
                    $loserCancelled = true;
                });
                $s->delay(2.0);
                throw new RuntimeException('should not reach');
            });

            $scope = $app->createScope();
            $value = $scope->race(...['fast' => $winner, 'slow' => $loser]);
            $scope->dispose();

            self::assertSame('winner', $value);
            self::assertTrue($loserCancelled, 'loser saw its own cancellation token fire');
        });
    }

    public function testConcurrentChildrenAppearInLedgerWithParentLinkage(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = $this->buildAppWithScopedCounter($ledger);

            $observedTree = null;
            $inner = Task::of(static function (ExecutionScope $s) use ($ledger, &$observedTree): string {
                if ($observedTree === null) {
                    $observedTree = $ledger->tree();
                }
                return 'ok';
            });
            $outer = Task::of(static function (ExecutionScope $s) use ($inner): array {
                return $s->concurrent(...['a' => $inner, 'b' => $inner]);
            });

            $scope = $app->createScope();
            $scope->execute($outer);
            $scope->dispose();

            self::assertNotNull($observedTree);
            // Tree at time of inner-body execution should have:
            //   - outer task run (parentId = null)
            //   - 1 or 2 inner runs with parentId = outer's id
            self::assertGreaterThanOrEqual(2, count($observedTree));

            // Find the outer (no parent)
            $outerSnap = null;
            foreach ($observedTree as $snap) {
                if ($snap->parentId === null) {
                    $outerSnap = $snap;
                    break;
                }
            }
            self::assertNotNull($outerSnap);

            // At least one snapshot has parentId = outer's id
            $anyChild = false;
            foreach ($observedTree as $snap) {
                if ($snap->parentId === $outerSnap->id) {
                    $anyChild = true;
                    break;
                }
            }
            self::assertTrue($anyChild, 'concurrent child has parentId pointing at outer run');
        });
    }

    private function buildAppWithScopedCounter(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, array $context): void
            {
                $services->scoped(Counter::class)
                    ->factory(static fn(): Counter => new Counter());
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }

    private function buildAppWithSingletonPool(InProcessLedger $ledger): Application
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, array $context): void
            {
                $services->singleton(PoolStub::class)
                    ->factory(static fn(): PoolStub => new PoolStub());
            }
        };
        return Application::starting([])
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile();
    }
}

final class Counter
{
    public int $n = 0;
}

final class PoolStub
{
    public int $checkouts = 0;
}
