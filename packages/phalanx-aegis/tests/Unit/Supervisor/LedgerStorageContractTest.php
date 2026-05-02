<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Supervisor\LockLease;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\RunState;
use Phalanx\Supervisor\SwooleTableLedger;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\WaitKind;
use Phalanx\Supervisor\WaitReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LedgerStorageContractTest extends TestCase
{
    #[DataProvider('ledgers')]
    public function testRegisterUpdateSnapshotTreeAndReap(LedgerStorage $ledger): void
    {
        $parent = self::taskRun('run-parent', null);
        $child = self::taskRun('run-child', 'run-parent');

        $ledger->register($parent);
        $ledger->register($child);

        $ledger->update('run-parent', static function (TaskRun $run): void {
            $run->state = RunState::Suspended;
            $run->currentWait = WaitReason::singleflight('user:42');
            $run->childIds[] = 'run-child';
            $run->leases[] = PoolLease::open('postgres/main', 'conn#1');
            $run->leases[] = LockLease::read('cache', 'user:42');
        });

        $snapshot = $ledger->snapshot('run-parent');
        self::assertNotNull($snapshot);
        self::assertSame(RunState::Suspended, $snapshot->state);
        self::assertSame(WaitKind::Singleflight, $snapshot->currentWait?->kind);
        self::assertSame(['run-child'], $snapshot->childIds);
        self::assertCount(2, $snapshot->leases);

        $tree = $ledger->tree('run-parent');
        self::assertCount(2, $tree);
        self::assertSame('run-parent', $tree[0]->id);
        self::assertSame('run-child', $tree[1]->id);
        self::assertSame(2, $ledger->liveCount());

        $ledger->complete('run-child', 'ok');
        self::assertSame(1, $ledger->liveCount());

        $ledger->reap('run-child');
        self::assertNull($ledger->find('run-child'));
    }

    /** @return iterable<string, array{LedgerStorage}> */
    public static function ledgers(): iterable
    {
        yield 'in-process' => [new InProcessLedger()];
        yield 'swoole-table' => [new SwooleTableLedger(64)];
    }

    private static function taskRun(string $id, ?string $parentId): TaskRun
    {
        return new TaskRun(
            id: $id,
            name: $id,
            parentId: $parentId,
            mode: DispatchMode::Inline,
            cancellation: CancellationToken::create(),
            startedAt: microtime(true),
        );
    }
}
