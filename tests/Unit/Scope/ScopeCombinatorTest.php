<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Engine\Wiring;
use Phalanx\Err\Err;
use Phalanx\Err\Fault;
use Phalanx\Err\FaultEscaped;
use Phalanx\Invocation\Ctx;
use Phalanx\Invocation\Executable;
use Phalanx\Mark\Mark;
use Phalanx\Scope\Backoff;
use Phalanx\Scope\Scope;
use Phalanx\Scope\SyncScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScopeCombinatorTest extends TestCase
{
    #[Test]
    public function parallelCollectsEveryOutcomePositionallyWithoutSynthesizingAnAggregate(): void
    {
        $probe = new KernelProbe(['ok', 'expected', 'ok']);

        $outcomes = $this->scope($probe)->parallel([new ProbeTask(), new ProbeTask(), new ProbeTask()]);

        self::assertCount(3, $outcomes);
        self::assertSame('done', $outcomes[0]);
        self::assertInstanceOf(ExpectedErr::class, $outcomes[1], 'the Err rides positionally; the parent assembles policy');
        self::assertSame('done', $outcomes[2]);
        self::assertSame(3, $probe->frames);
    }

    #[Test]
    public function aFaultingBranchDoesNotAbandonItsSiblings(): void
    {
        $probe = new KernelProbe(['ok', 'throw', 'ok']);

        try {
            $this->scope($probe)->parallel([new ProbeTask(), new ProbeTask(), new ProbeTask()]);

            self::fail('Expected FaultEscaped after collection.');
        } catch (FaultEscaped) {
            self::assertSame(3, $probe->frames, 'all branches complete before the fault unwinds');
        }
    }

    #[Test]
    public function mapBuildsWorkUnitsThroughTheFactoryAndPreservesOrder(): void
    {
        $outcomes = SyncScope::root(new Wiring())->map(
            ['alpha', 'beta'],
            static fn (string $item): ItemTask => new ItemTask(item: $item),
        );

        self::assertSame(['item:alpha', 'item:beta'], $outcomes);
    }

    #[Test]
    public function raceReturnsTheFirstCompletedOutcomeAndNeverDispatchesTheLosers(): void
    {
        $probe = new KernelProbe(['expected', 'ok']);

        $outcome = $this->scope($probe)->race([new ProbeTask(), new ProbeTask()]);

        self::assertInstanceOf(ExpectedErr::class, $outcome, 'an Err counts as completed');
        self::assertSame(1, $probe->frames, 'losers are never dispatched in the sync backend');
    }

    #[Test]
    public function seriesThreadsEachStepsValueIntoTheNextFactory(): void
    {
        $outcome = SyncScope::root(new Wiring())->series(
            new ItemTask(item: 'alpha'),
            static fn (string $value): ItemTask => new ItemTask(item: $value . '+beta'),
            static fn (string $value): ItemTask => new ItemTask(item: $value . '+gamma'),
        );

        self::assertSame('item:item:item:alpha+beta+gamma', $outcome);
    }

    #[Test]
    public function theFirstErrShortCircuitsTheSeriesAndLaterFactoriesNeverRun(): void
    {
        $probe = new KernelProbe(['expected']);
        $factoryCalls = 0;

        $outcome = $this->scope($probe)->series(
            new ProbeTask(),
            static function (string $value) use (&$factoryCalls): ItemTask {
                $factoryCalls++;

                return new ItemTask(item: $value);
            },
        );

        self::assertInstanceOf(ExpectedErr::class, $outcome);
        self::assertSame(0, $factoryCalls, 'the chain stops before any later factory runs');
        self::assertSame(1, $probe->frames);
    }

    #[Test]
    public function aRetriedStepsIntermediateAttemptsAreInvisibleToTheChain(): void
    {
        $probe = new KernelProbe(['transient', 'ok']);
        $received = [];

        $outcome = $this->scope($probe)->withRetry(2, Backoff::none())->series(
            new ProbeTask(),
            static function (string $value) use (&$received): ItemTask {
                $received[] = $value;

                return new ItemTask(item: $value);
            },
        );

        self::assertSame('item:done', $outcome);
        self::assertSame(['done'], $received, 'only the post-supervision outcome reaches the chain (EM1.3)');
        self::assertSame(2, $probe->frames);
    }

    #[Test]
    public function eachSeriesStepSpendsTheDispatchSiteBudgetIndependently(): void
    {
        $probe = new KernelProbe(['transient', 'ok', 'transient', 'ok']);

        $outcome = $this->scope($probe)->withRetry(2, Backoff::none())->series(
            new ProbeTask(),
            static fn (string $value): ProbeTask => new ProbeTask(),
        );

        self::assertSame('done', $outcome);
        self::assertSame(4, $probe->frames, 'each step retries under its own copy of the dispatch budget');
        self::assertSame([1, 2, 1, 2], $probe->attempts);
    }

    #[Test]
    public function aFaultAbsorbedAtTheDispatchSiteShortCircuitsTheSeries(): void
    {
        $probe = new KernelProbe(['throw']);
        $factoryCalls = 0;

        $outcome = $this->scope($probe)
            ->faultsAs(static fn (Fault $fault): Err|Fault => $fault->isA(RuntimeException::class) ? new ExpectedErr() : $fault)
            ->series(
                new ProbeTask(),
                static function (string $value) use (&$factoryCalls): ItemTask {
                    $factoryCalls++;

                    return new ItemTask(item: $value);
                },
            );

        self::assertInstanceOf(ExpectedErr::class, $outcome);
        self::assertSame(0, $factoryCalls);
    }

    #[Test]
    public function nestedDeadlinesOnlyEverNarrow(): void
    {
        $scope = SyncScope::root(new Wiring())
            ->withDeadline(Mark::ms(50))
            ->withDeadline(Mark::h(1));

        self::assertTrue($scope->remaining()->lte(Mark::ms(50)), 'an inner deadline cannot extend the outer one');
    }

    #[Test]
    public function narrowingNeverMutatesTheOriginalScope(): void
    {
        $probe = new KernelProbe(['transient', 'transient']);
        $base = $this->scope($probe);

        $base->withRetry(5, Backoff::none());

        $outcome = $base->run(new ProbeTask());

        self::assertInstanceOf(TransientErr::class, $outcome);
        self::assertSame(1, $probe->frames, 'the base scope keeps its fail-fast budget');
    }

    private function scope(KernelProbe $probe): Scope
    {
        $wiring = new Wiring();
        $wiring->provide(ProbeCaps::class, static fn (Scope $frame): ProbeCaps => new ProbeCaps($probe, $frame));

        return SyncScope::root($wiring);
    }
}

/** @implements Executable<string> */
final class ItemTask implements Executable
{
    public function __construct(
        private(set) string $item,
    ) {
    }

    public function __invoke(Ctx $ctx): string
    {
        return 'item:' . $this->item;
    }
}
