<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\WaitKind;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Task;
use PHPUnit\Framework\TestCase;

final class WaitReasonRecordingTest extends TestCase
{
    public function testCallWithWaitReasonRecordsItOnTheActiveRun(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);

        $observed = null;

        self::bootRuntimeHooks();
        \OpenSwoole\Coroutine::run(static function () use ($app, $ledger, &$observed): void {
            $scope = $app->createScope();
            $task = Task::of(static function (ExecutionScope $s) use ($ledger, &$observed): void {
                // Spawn a sibling coroutine that snapshots the tree mid-suspend.
                \OpenSwoole\Coroutine::create(static function () use ($ledger, &$observed): void {
                    \OpenSwoole\Coroutine::usleep(10_000);
                    foreach ($ledger->tree() as $snap) {
                        if ($snap->currentWait !== null) {
                            $observed = $snap->currentWait;
                            return;
                        }
                    }
                });

                $s->call(
                    static function (): void {
                        \OpenSwoole\Coroutine::usleep(50_000);
                    },
                    WaitReason::http('GET', 'https://api.example.test/users/1'),
                );
            });
            $scope->execute($task);
        });

        self::assertNotNull($observed);
        self::assertSame(WaitKind::Http, $observed->kind);
        self::assertStringContainsString('api.example.test', $observed->detail);
    }

    public function testDelayRecordsDelayWaitReason(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);

        $observed = null;

        self::bootRuntimeHooks();
        \OpenSwoole\Coroutine::run(static function () use ($app, $ledger, &$observed): void {
            $scope = $app->createScope();
            $task = Task::of(static function (ExecutionScope $s) use ($ledger, &$observed): void {
                \OpenSwoole\Coroutine::create(static function () use ($ledger, &$observed): void {
                    \OpenSwoole\Coroutine::usleep(10_000);
                    foreach ($ledger->tree() as $snap) {
                        if ($snap->currentWait !== null) {
                            $observed = $snap->currentWait;
                            return;
                        }
                    }
                });

                $s->delay(0.05);
            });
            $scope->execute($task);
        });

        self::assertNotNull($observed);
        self::assertSame(WaitKind::Delay, $observed->kind);
    }

    public function testCallWithoutWaitReasonLeavesCurrentWaitNull(): void
    {
        $ledger = new InProcessLedger();
        $app = $this->buildApp($ledger);

        $observedWait = 'sentinel';

        self::bootRuntimeHooks();
        \OpenSwoole\Coroutine::run(static function () use ($app, $ledger, &$observedWait): void {
            $scope = $app->createScope();
            $task = Task::of(static function (ExecutionScope $s) use ($ledger, &$observedWait): void {
                \OpenSwoole\Coroutine::create(static function () use ($ledger, &$observedWait): void {
                    \OpenSwoole\Coroutine::usleep(10_000);
                    foreach ($ledger->tree() as $snap) {
                        $observedWait = $snap->currentWait;
                        return;
                    }
                });

                $s->call(static function (): void {
                    \OpenSwoole\Coroutine::usleep(50_000);
                });
            });
            $scope->execute($task);
        });

        self::assertNull($observedWait);
    }

    private static function bootRuntimeHooks(): void
    {
        RuntimeHooks::ensure(RuntimePolicy::phalanxManaged());
    }

    private function buildApp(InProcessLedger $ledger): Application
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
