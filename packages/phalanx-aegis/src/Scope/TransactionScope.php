<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Supervisor\TransactionLease;

/**
 * Narrowed scope for transaction bodies.
 *
 * A transaction may resolve services, suspend through the supervised call path,
 * and register cleanup. It deliberately does not expose TaskExecutor fan-out
 * methods such as concurrent(), go(), or inWorker().
 */
interface TransactionScope extends Scope, Suspendable, Cancellable, Disposable
{
    public function delay(float $seconds): void;

    public function transactionLease(): TransactionLease;
}
