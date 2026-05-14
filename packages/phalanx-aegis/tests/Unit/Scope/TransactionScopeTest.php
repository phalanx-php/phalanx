<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Boot\AppContext;
use Phalanx\Application;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\TransactionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LeaseViolation;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Task;
use Phalanx\Testing\Assert as PhalanxAssert;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Trace\Trace;

final class TransactionScopeTest extends PhalanxTestCase
{
    public function testTransactionRegistersAndReleasesLeaseAroundBody(): void
    {
        $ledger = new InProcessLedger();
        $probe = new class {
            public ?string $heldInside = null;
        };

        $this->scope->run(static function (ExecutionScope $_scope) use ($ledger, $probe): void {
            $inner = self::buildScope($ledger);

            $value = $inner->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->transaction(
                    TransactionLease::open('postgres/main', 'tx#1'),
                    static function (TransactionScope $tx) use ($ledger, $probe): string {
                        $snapshot = $ledger->tree()[0] ?? null;
                        self::assertNotNull($snapshot);
                        $probe->heldInside = $snapshot->leases[0]['domain'] ?? null;

                        return $tx->transactionLease()->key;
                    },
                ),
            ));

            self::assertSame('tx#1', $value);
            self::assertSame('postgres/main', $probe->heldInside);

            $inner->dispose();
        });

        PhalanxAssert::assertNoLiveTasks(new Supervisor($ledger, new Trace()));
    }

    public function testTransactionScopeDoesNotExposeFanOutExecutorMethods(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $inner = self::buildScope(new InProcessLedger());

            $inner->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->transaction(
                    TransactionLease::open('postgres/main', 'tx#2'),
                    static function (TransactionScope $tx): void {
                        self::assertFalse(method_exists($tx, 'concurrent'));
                        self::assertFalse(method_exists($tx, 'go'));
                        self::assertFalse(method_exists($tx, 'inWorker'));
                    },
                ),
            ));

            $inner->dispose();
        });
    }

    public function testTransactionScopeRejectsExternalWaits(): void
    {
        $thrown = null;

        $this->scope->run(static function (ExecutionScope $_scope) use (&$thrown): void {
            $inner = self::buildScope(new InProcessLedger());

            try {
                $inner->execute(Task::of(
                    static fn(ExecutionScope $s): mixed => $s->transaction(
                        TransactionLease::open('postgres/main', 'tx#3'),
                        static fn(TransactionScope $tx): mixed => $tx->call(
                            static fn(): string => 'unreachable',
                            WaitReason::redis('PUBLISH events'),
                        ),
                    ),
                ));
            } catch (LeaseViolation $e) {
                $thrown = $e;
            }

            $inner->dispose();
        });

        self::assertNotNull($thrown);
        self::assertSame('PHX-TXN-001', $thrown->phxCode);
    }

    public function testTransactionScopeAllowsLocalWaitsAndInheritedScopedServices(): void
    {
        $value = $this->scope->run(static function (ExecutionScope $_scope): string {
            $inner = self::buildScope(new InProcessLedger());
            self::assertInstanceOf(ExecutionLifecycleScope::class, $inner);
            $inner->bindScopedInstance(TransactionState::class, new TransactionState('acme'), inherit: true);

            $result = $inner->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->transaction(
                    TransactionLease::open('postgres/main', 'tx#4'),
                    static function (TransactionScope $tx): string {
                        $tx->delay(0.001);

                        return $tx->service(TransactionState::class)->tenant;
                    },
                ),
            ));

            $inner->dispose();

            return $result;
        });

        self::assertSame('acme', $value);
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

final readonly class TransactionState
{
    public function __construct(
        public string $tenant,
    ) {
    }
}
