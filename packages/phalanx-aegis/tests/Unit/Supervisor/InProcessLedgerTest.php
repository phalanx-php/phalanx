<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\RunState;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\WaitReason;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InProcessLedgerTest extends TestCase
{
    public function testRegisterAndFindReturnsSameInstance(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-001', 'TestTask');

        $ledger->register($run);

        self::assertSame($run, $ledger->find('run-001'));
    }

    public function testFindUnknownRunReturnsNull(): void
    {
        $ledger = new InProcessLedger();
        self::assertNull($ledger->find('does-not-exist'));
    }

    public function testCompleteTransitionsToTerminalState(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-002', 'CompleteMe');
        $ledger->register($run);

        $ledger->complete('run-002', ['ok' => true]);

        self::assertSame(RunState::Completed, $run->state);
        self::assertSame(['ok' => true], $run->value);
        self::assertNotNull($run->endedAt);
        self::assertTrue($run->isTerminal());
    }

    public function testFailTransitionsToFailedWithError(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-003', 'FailMe');
        $ledger->register($run);

        $err = new RuntimeException('boom');
        $ledger->fail('run-003', $err);

        self::assertSame(RunState::Failed, $run->state);
        self::assertSame($err, $run->error);
        self::assertNotNull($run->endedAt);
    }

    public function testCancelTransitionsToCancelled(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-004', 'CancelMe');
        $ledger->register($run);

        $ledger->cancel('run-004');

        self::assertSame(RunState::Cancelled, $run->state);
        self::assertNotNull($run->endedAt);
    }

    public function testIntentOperationsUpdateRunState(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-005', 'PatchMe');
        $ledger->register($run);

        $ledger->markRunning('run-005');
        $ledger->beginWait('run-005', WaitReason::custom('warming up'));

        self::assertSame(RunState::Suspended, $run->state);
        self::assertNotNull($run->currentWait);
        self::assertSame('warming up', $run->currentWait->detail);

        $ledger->clearWait('run-005');
        self::assertSame(RunState::Running, $run->state);
        self::assertNull($run->currentWait);
    }

    public function testLiveCountExcludesTerminalRuns(): void
    {
        $ledger = new InProcessLedger();
        $a = $this->makeRun('a', 'A');
        $b = $this->makeRun('b', 'B');
        $c = $this->makeRun('c', 'C');
        $ledger->register($a);
        $ledger->register($b);
        $ledger->register($c);

        $ledger->complete('a', null);
        $ledger->fail('b', new RuntimeException());

        self::assertSame(1, $ledger->liveCount());
    }

    public function testReapRemovesRunFromLedger(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-006', 'ReapMe');
        $ledger->register($run);
        $ledger->complete('run-006', null);

        $ledger->reap('run-006');

        self::assertNull($ledger->find('run-006'));
        self::assertSame(0, $ledger->liveCount());
    }

    public function testSnapshotIsDetachedFromLiveRun(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-007', 'Snap');
        $ledger->register($run);
        $ledger->markRunning('run-007');

        $snap = $ledger->snapshot('run-007');

        self::assertNotNull($snap);
        self::assertSame('run-007', $snap->id);
        self::assertSame(RunState::Running, $snap->state);

        // Mutating the live run does not change the snapshot.
        $ledger->cancel('run-007');
        self::assertSame(RunState::Running, $snap->state);
    }

    public function testTreeWithoutRootReturnsEveryLiveRun(): void
    {
        $ledger = new InProcessLedger();
        $a = $this->makeRun('a', 'A');
        $b = $this->makeRun('b', 'B');
        $ledger->register($a);
        $ledger->register($b);

        $tree = $ledger->tree();

        self::assertCount(2, $tree);
    }

    public function testTreeFromRootIncludesTransitiveChildren(): void
    {
        $ledger = new InProcessLedger();
        $root = $this->makeRun('root', 'Root');
        $child = $this->makeRun('child', 'Child');
        $grand = $this->makeRun('grand', 'Grand');

        $ledger->register($root);
        $ledger->register($child);
        $ledger->register($grand);
        $ledger->addChild('root', 'child');
        $ledger->addChild('child', 'grand');

        $tree = $ledger->tree('root');

        self::assertCount(3, $tree);
        self::assertSame('root', $tree[0]->id);
        self::assertSame('child', $tree[1]->id);
        self::assertSame('grand', $tree[2]->id);
    }

    public function testLeaseOperationsUpdateRunLeases(): void
    {
        $ledger = new InProcessLedger();
        $run = $this->makeRun('run-008', 'LeaseMe');
        $lease = PoolLease::open('postgres/main', 'conn#1');

        $ledger->register($run);
        $ledger->addLease('run-008', $lease);

        self::assertCount(1, $ledger->snapshot('run-008')?->leases);

        $ledger->releaseLease('run-008', $lease);

        self::assertSame([], $ledger->snapshot('run-008')?->leases);
    }

    private function makeRun(string $id, string $name): TaskRun
    {
        return new TaskRun(
            id: $id,
            name: $name,
            parentId: null,
            mode: DispatchMode::Inline,
            cancellation: CancellationToken::create(),
            startedAt: microtime(true),
        );
    }
}
