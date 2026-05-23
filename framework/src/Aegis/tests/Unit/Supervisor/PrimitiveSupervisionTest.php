<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Trace\Trace;

final class PrimitiveSupervisionTest extends PhalanxTestCase
{
    public function testSeriesTasksAreSupervisedAndParented(): void
    {
        $ledger = new InProcessLedger();
        $probe = new class {
            public ?string $observedParent = null;
        };

        $this->scope->run(static function (ExecutionScope $_scope) use ($ledger, $probe): void {
            $inner = self::buildScope($ledger);

            $inner->execute(Task::of(static function (ExecutionScope $s) use ($ledger, $probe): void {
                $s->series(...[
                    Task::of(static function () use ($ledger, $probe): string {
                        $tree = $ledger->tree();
                        self::assertCount(2, $tree);
                        PhalanxAssert::assertTaskTreeContains(
                            new Supervisor($ledger, new Trace()),
                            'PrimitiveSupervisionTest.php',
                        );

                        foreach ($tree as $run) {
                            if ($run->parentId !== null) {
                                $probe->observedParent = $run->parentId;
                            }
                        }

                        return 'ok';
                    }),
                ]);
            }));

            self::assertNotNull($probe->observedParent);
            $inner->dispose();
        });

        PhalanxAssert::assertNoLiveTasks(new Supervisor($ledger, new Trace()));
    }

    public function testRetryTasksAreSupervisedPerAttempt(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $inner = self::buildScope($ledger);
            $probe = new class {
                public int $attempts = 0;

                /** @var list<int> */
                public array $liveCounts = [];
            };

            $value = $inner->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->retry(
                    Task::of(static function () use ($ledger, $probe): string {
                        $probe->attempts++;
                        $probe->liveCounts[] = $ledger->liveCount();
                        if ($probe->attempts === 1) {
                            throw new \RuntimeException('again');
                        }

                        return 'done';
                    }),
                    RetryPolicy::fixed(2, 1),
                ),
            ));

            self::assertSame('done', $value);
            self::assertSame(2, $probe->attempts);
            self::assertSame([2, 2], $probe->liveCounts);
            self::assertSame(0, $ledger->liveCount());
            $inner->dispose();
        });
    }

    public function testSingleflightOwnerTaskIsSupervised(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $ledger = new InProcessLedger();
            $inner = self::buildScope($ledger);
            $probe = new class {
                public ?int $observedLive = null;
            };

            $value = $inner->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->singleflight(
                    'user:42',
                    Task::of(static function () use ($ledger, $probe): string {
                        $probe->observedLive = $ledger->liveCount();
                        return 'owner';
                    }),
                ),
            ));

            self::assertSame('owner', $value);
            self::assertSame(2, $probe->observedLive);
            self::assertSame(0, $ledger->liveCount());
            $inner->dispose();
        });
    }

    public function testCancelledSingleflightWaiterDoesNotCancelOwner(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $inner = self::buildScope(new InProcessLedger());

            $bag = $inner->settle(
                owner: Task::of(static fn(ExecutionScope $s): mixed => $s->singleflight(
                    'shared',
                    Task::of(static function (ExecutionScope $owner): string {
                        $owner->delay(0.05);
                        return 'owner-result';
                    }),
                )),
                waiter: Task::of(static function (ExecutionScope $s): string {
                    \OpenSwoole\Coroutine::create(static function () use ($s): void {
                        \OpenSwoole\Coroutine::usleep(10_000);
                        $s->cancellation()->cancel();
                    });

                    try {
                        $s->singleflight(
                            'shared',
                            Task::of(static fn(): string => 'should-not-run'),
                        );
                    } catch (Cancelled) {
                        return 'waiter-cancelled';
                    }

                    return 'unexpected';
                }),
            );

            self::assertSame('owner-result', $bag->get('owner'));
            self::assertSame('waiter-cancelled', $bag->get('waiter'));
            $inner->dispose();
        });
    }

    private static function buildScope(InProcessLedger $ledger): ExecutionScope
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };

        return Application::starting()
            ->providers($bundle)
            ->withLedger($ledger)
            ->compile()
            ->createScope();
    }
}
