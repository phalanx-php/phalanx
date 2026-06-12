<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Engine\Wiring;
use Phalanx\Err\FaultEscaped;
use Phalanx\Invocation\Ctx;
use Phalanx\Invocation\Executable;
use Phalanx\Mark\Mark;
use Phalanx\Scope\Backoff;
use Phalanx\Scope\Scope;
use Phalanx\Scope\SyncScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
