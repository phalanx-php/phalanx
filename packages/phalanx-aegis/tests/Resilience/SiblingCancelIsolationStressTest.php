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
 * Cross-checks the isolation invariant: a child's cancellation token is
 * derived from the parent's, but siblings do NOT share a token. Cancelling
 * sibling A must not propagate to sibling B.
 *
 * Cancelling the parent must propagate to ALL children.
 */
final class SiblingCancelIsolationStressTest extends CoroutineTestCase
{
    public function testParentCancelTerminatesAllSiblingsQuickly(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();
            $token = $scope->cancellation();

            // Schedule cancellation after siblings are running.
            \OpenSwoole\Coroutine::create(static function () use ($token): void {
                \OpenSwoole\Coroutine::usleep(20_000);
                $token->cancel();
            });

            $tasks = [];
            $siblingCount = 50;
            for ($i = 0; $i < $siblingCount; $i++) {
                $tasks["sib-{$i}"] = Task::of(static function (ExecutionScope $s): void {
                    $s->delay(5.0);
                });
            }

            $start = microtime(true);
            $caught = null;
            try {
                $scope->concurrent(...$tasks);
            } catch (Cancelled $e) {
                $caught = $e;
            }
            $elapsed = microtime(true) - $start;

            self::assertNotNull($caught, 'parent cancel should propagate to children and surface');
            self::assertLessThan(1.0, $elapsed, sprintf('took %.3fs', $elapsed));

            $scope->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testCompletedSiblingDoesNotCancelOthers(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();

            // One sibling completes fast, the others a bit slower. All must
            // complete; the fast one must not signal cancellation to peers.
            $tasks = [
                'fast' => Task::of(static fn(ExecutionScope $s): int => 1),
                'slow-a' => Task::of(static function (ExecutionScope $s): int {
                    $s->delay(0.05);
                    return 2;
                }),
                'slow-b' => Task::of(static function (ExecutionScope $s): int {
                    $s->delay(0.05);
                    return 3;
                }),
                'slow-c' => Task::of(static function (ExecutionScope $s): int {
                    $s->delay(0.05);
                    return 4;
                }),
            ];

            $results = $scope->concurrent(...$tasks);

            self::assertSame(['fast' => 1, 'slow-a' => 2, 'slow-b' => 3, 'slow-c' => 4], $results);

            $scope->dispose();
            self::assertSame(0, $ledger->liveCount());
        });
    }

    public function testRaceCancelsLosersOnly(): void
    {
        $this->runInCoroutine(function (): void {
            $ledger = new InProcessLedger();
            $app = self::buildApp($ledger);
            $scope = $app->createScope();

            $start = microtime(true);
            $value = $scope->race(...[
                Task::of(static fn(ExecutionScope $s): int => 1),  // wins
                Task::of(static function (ExecutionScope $s): never {
                    $s->delay(5.0);
                    throw new \RuntimeException('should be cancelled');
                }),
                Task::of(static function (ExecutionScope $s): never {
                    $s->delay(5.0);
                    throw new \RuntimeException('should be cancelled');
                }),
            ]);
            $elapsed = microtime(true) - $start;

            self::assertSame(1, $value);
            self::assertLessThan(1.0, $elapsed, 'losers cancelled fast');

            $scope->dispose();
            self::assertSame(0, $ledger->liveCount());
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
