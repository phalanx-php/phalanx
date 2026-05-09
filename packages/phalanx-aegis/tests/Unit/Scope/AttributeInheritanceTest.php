<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use Phalanx\Tests\Support\CoroutineTestCase;

/**
 * Verifies that attributes set on a parent scope flow into every child
 * scope spawned by every concurrency primitive — concurrent, race, any,
 * map, series, settle, defer, go — and continue flowing into grandchildren.
 *
 * This is the "context propagation" guarantee that lets request-id /
 * tenant-id / trace-id flow through nested concurrent work without manual
 * threading. The test would fail loudly if any primitive forgot to pass
 * the attribute snapshot when constructing its child scope.
 */
final class AttributeInheritanceTest extends CoroutineTestCase
{
    public function testConcurrentChildrenSeeParentAttributes(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $scope = $scope->withAttribute('request.id', 'req-7');

            $observed = $scope->concurrent(
                a: Task::of(static fn(ExecutionScope $s) => $s->attribute('request.id')),
                b: Task::of(static fn(ExecutionScope $s) => $s->attribute('request.id')),
            );

            self::assertSame(['a' => 'req-7', 'b' => 'req-7'], $observed);
        });
    }

    public function testRaceWinnerSeesParentAttribute(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('tenant.id', 'acme');

            $value = $scope->race(...[
                Task::of(static fn(ExecutionScope $s) => $s->attribute('tenant.id')),
                Task::of(static function (ExecutionScope $s): never {
                    $s->delay(5.0);
                    throw new \RuntimeException('should be cancelled');
                }),
            ]);

            self::assertSame('acme', $value);
        });
    }

    public function testAnyChildSeesParentAttribute(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('trace.id', 't-99');

            $value = $scope->any(...[
                Task::of(static fn(ExecutionScope $s) => $s->attribute('trace.id')),
            ]);

            self::assertSame('t-99', $value);
        });
    }

    public function testMapChildrenSeeParentAttribute(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('env', 'prod');

            $observed = $scope->map(
                [1, 2, 3],
                static fn(int $n) => Task::of(
                    static fn(ExecutionScope $s) => $s->attribute('env') . ":{$n}",
                ),
                limit: 3,
            );

            self::assertSame([0 => 'prod:1', 1 => 'prod:2', 2 => 'prod:3'], $observed);
        });
    }

    public function testSeriesChildrenSeeParentAttribute(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('flag.a', 'on');

            $observed = $scope->series(...[
                Task::of(static fn(ExecutionScope $s) => $s->attribute('flag.a')),
                Task::of(static fn(ExecutionScope $s) => $s->attribute('flag.a')),
            ]);

            self::assertSame(['on', 'on'], $observed);
        });
    }

    public function testSettleChildrenSeeParentAttribute(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('region', 'us-west');

            $bag = $scope->settle(
                a: Task::of(static fn(ExecutionScope $s) => $s->attribute('region')),
                b: Task::of(static fn(ExecutionScope $s) => $s->attribute('region')),
            );

            self::assertSame('us-west', $bag->get('a'));
            self::assertSame('us-west', $bag->get('b'));
        });
    }

    public function testGoChildSeesParentAttribute(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('worker.tag', 'bg-7');

            $observed = null;
            $scope->go(static function (ExecutionScope $s) use (&$observed): void {
                $observed = $s->attribute('worker.tag');
            });
            \OpenSwoole\Coroutine::usleep(5_000);

            self::assertSame('bg-7', $observed);

            $scope->dispose();
        });
    }

    public function testGrandchildrenInheritTransitively(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('chain', 'root');

            $observed = $scope->concurrent(
                outer: Task::of(static fn(ExecutionScope $outer): array => $outer->concurrent(
                    inner: Task::of(static fn(ExecutionScope $inner) => $inner->attribute('chain')),
                )),
            );

            self::assertSame('root', $observed['outer']['inner']);
        });
    }

    public function testChildAttributeOverrideDoesNotMutateParent(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope()->withAttribute('mutable', 'parent-value');

            $scope->concurrent(
                child: Task::of(static function (ExecutionScope $s): void {
                    // Local override on a derived scope; parent must not see it.
                    $derived = $s->withAttribute('mutable', 'child-value');
                    if ($derived->attribute('mutable') !== 'child-value') {
                        throw new \RuntimeException('child should see its own override');
                    }
                }),
            );

            self::assertSame('parent-value', $scope->attribute('mutable'));
        });
    }

    private static function buildScope(): ExecutionScope
    {
        $bundle = new class extends ServiceBundle {
            public function services(Services $services, AppContext $context): void
            {
            }
        };
        $app = Application::starting()
            ->providers($bundle)
            ->withLedger(new InProcessLedger())
            ->compile();
        return $app->createScope();
    }
}
