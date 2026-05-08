<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Boot\AppContext;
use Phalanx\Application;
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
use Phalanx\Tests\Support\CoroutineTestCase;
use Phalanx\Trace\Trace;

final class TransactionScopeTest extends CoroutineTestCase
{
    public function testTransactionRegistersAndReleasesLeaseAroundBody(): void
    {
        $ledger = new InProcessLedger();
        $probe = new class {
            public ?string $heldInside = null;
        };

        $this->runScopedWithLedger($ledger, static function (ExecutionScope $scope) use ($ledger, $probe): void {
            $value = $scope->execute(Task::of(
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
        });

        PhalanxAssert::assertNoLiveTasks(new Supervisor($ledger, new Trace()));
    }

    public function testTransactionScopeDoesNotExposeFanOutExecutorMethods(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope(new InProcessLedger());

            $scope->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->transaction(
                    TransactionLease::open('postgres/main', 'tx#2'),
                    static function (TransactionScope $tx): void {
                        self::assertFalse(method_exists($tx, 'concurrent'));
                        self::assertFalse(method_exists($tx, 'go'));
                        self::assertFalse(method_exists($tx, 'inWorker'));
                    },
                ),
            ));
        });
    }

    public function testTransactionScopeRejectsExternalWaits(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope(new InProcessLedger());

            $thrown = null;
            try {
                $scope->execute(Task::of(
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

            self::assertNotNull($thrown);
            self::assertSame('PHX-TXN-001', $thrown->phxCode);
        });
    }

    public function testTransactionScopeAllowsLocalWaitsAndAttributeDerivation(): void
    {
        $this->runInCoroutine(function (): void {
            $scope = self::buildScope(new InProcessLedger())->withAttribute('tenant', 'acme');

            $value = $scope->execute(Task::of(
                static fn(ExecutionScope $s): mixed => $s->transaction(
                    TransactionLease::open('postgres/main', 'tx#4'),
                    static function (TransactionScope $tx): string {
                        $derived = $tx->withAttribute('tenant', 'beta');
                        $derived->delay(0.001);

                        return $tx->attribute('tenant') . '/' . $derived->attribute('tenant');
                    },
                ),
            ));

            self::assertSame('acme/beta', $value);
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
