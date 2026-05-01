<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Supervisor;

use Phalanx\Cancellation\CancellationToken;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LeaseViolation;
use Phalanx\Supervisor\LockLease;
use Phalanx\Supervisor\PoolLease;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\TestCase;

final class LeaseTrackingTest extends TestCase
{
    public function testRegisterAndReleasePoolLeaseRoundTrip(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        $lease = PoolLease::open('postgres/main', 'conn#1');
        $supervisor->registerLease($run, $lease);

        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(1, $snapshot->leases);
        self::assertSame('postgres/main', $snapshot->leases[0]['domain']);

        $supervisor->releaseLease($run, $lease);
        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(0, $snapshot->leases);
    }

    public function testNestedPoolAcquireTriggersPhxPool001(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        $supervisor->registerLease($run, PoolLease::open('postgres/main', 'conn#1'));

        $thrown = null;
        try {
            $supervisor->registerLease($run, PoolLease::open('postgres/main', 'conn#2'));
        } catch (LeaseViolation $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertSame('PHX-POOL-001', $thrown->phxCode);
        self::assertStringContainsString('postgres/main', $thrown->detail);
    }

    public function testDifferentPoolsCanCoexist(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        $supervisor->registerLease($run, PoolLease::open('postgres/main', 'conn#1'));
        $supervisor->registerLease($run, PoolLease::open('redis/cache', 'conn#1'));

        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(2, $snapshot->leases);
    }

    public function testOutOfOrderLockAcquireTriggersPhxLock001(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        // Hold lock on key "user:42"
        $supervisor->registerLease($run, LockLease::write('cache', 'user:42'));

        // Now try to acquire "user:10" — strcmp("user:10", "user:42") < 0,
        // so this is out-of-canonical-order. Two tasks doing inverse
        // multi-key acquires (one A then B, the other B then A) is the
        // textbook deadlock; canonical sorting prevents it.
        $thrown = null;
        try {
            $supervisor->registerLease($run, LockLease::write('cache', 'user:10'));
        } catch (LeaseViolation $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown);
        self::assertSame('PHX-LOCK-001', $thrown->phxCode);
    }

    public function testInOrderLockAcquireSucceeds(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        $supervisor->registerLease($run, LockLease::write('cache', 'user:10'));
        $supervisor->registerLease($run, LockLease::write('cache', 'user:42'));

        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(2, $snapshot->leases);
    }

    public function testReentrantLockOnSameKeyAllowed(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        // Re-entering the same key (e.g. nested function both wanting
        // a read lock on user:42) is fine — the lock manager handles
        // reference counting, not us.
        $supervisor->registerLease($run, LockLease::read('cache', 'user:42'));
        $supervisor->registerLease($run, LockLease::read('cache', 'user:42'));

        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(2, $snapshot->leases);
    }

    public function testTransactionLeaseRoundTrip(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        $tx = TransactionLease::open('postgres/main', 'tx#42');
        $supervisor->registerLease($run, $tx);

        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertSame('exclusive', $snapshot->leases[0]['mode']);
    }

    public function testWithLeaseReleasesOnSuccess(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        $value = $supervisor->withLease(
            $run,
            PoolLease::open('postgres/main', 'conn#1'),
            static fn(): string => 'ok',
        );

        self::assertSame('ok', $value);
        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(0, $snapshot->leases);
    }

    public function testWithLeaseReleasesOnException(): void
    {
        $supervisor = $this->buildSupervisor();
        $run = $this->openRun($supervisor);

        try {
            $supervisor->withLease(
                $run,
                PoolLease::open('postgres/main', 'conn#1'),
                static function (): never {
                    throw new \RuntimeException('boom');
                },
            );
            self::fail('expected throw');
        } catch (\RuntimeException) {
        }

        $snapshot = $supervisor->ledger->snapshot($run->id);
        self::assertNotNull($snapshot);
        self::assertCount(0, $snapshot->leases);
    }

    public function testReapEmitsPhxLease001ForOrphanedLease(): void
    {
        $trace = new Trace();
        $ledger = new InProcessLedger();
        $supervisor = new Supervisor($ledger, $trace);
        $run = $supervisor->start(
            new \Phalanx\Tests\Unit\Supervisor\NoopTask(),
            new \Phalanx\Tests\Unit\Supervisor\BareScopeStub(),
            DispatchMode::Inline,
            'NoopTask',
        );

        // Forgot to release.
        $supervisor->registerLease($run, PoolLease::open('postgres/main', 'conn#1'));
        $supervisor->complete($run, null);
        $supervisor->reap($run);

        $events = array_values(array_filter(
            $trace->events(),
            static fn($e) => $e->name === 'PHX-LEASE-001',
        ));
        self::assertCount(1, $events);
        self::assertSame('postgres/main', $events[0]->attrs['domain']);
    }

    private function buildSupervisor(): Supervisor
    {
        return new Supervisor(new InProcessLedger(), new Trace());
    }

    private function openRun(Supervisor $supervisor): TaskRun
    {
        return $supervisor->start(
            new NoopTask(),
            new BareScopeStub(),
            DispatchMode::Inline,
            'NoopTask',
        );
    }
}

/**
 * Minimal Executable for opening a TaskRun in lease tests.
 */
final class NoopTask implements \Phalanx\Task\Executable
{
    public function __invoke(\Phalanx\Scope\ExecutionScope $scope): mixed
    {
        return null;
    }
}

/**
 * Bare Scope stub for opening a TaskRun without booting a full Application.
 */
final class BareScopeStub implements \Phalanx\Scope\Scope
{
    public function service(string $type): object
    {
        throw new \RuntimeException('BareScopeStub: service resolution not supported');
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function withAttribute(string $key, mixed $value): \Phalanx\Scope\Scope
    {
        return $this;
    }

    public function trace(): Trace
    {
        return new Trace();
    }
}
