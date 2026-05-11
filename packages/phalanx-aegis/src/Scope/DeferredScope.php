<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Supervisor\WaitReason;
use RuntimeException;

/**
 * Coroutine-local scope proxy. Long-lived service classes (constructed once,
 * called many times across coroutines) inject DeferredScope into their
 * constructor. Each method call on the service resolves to the *currently
 * installed* scope via CoroutineScopeRegistry, which the framework re-installs
 * on every coroutine spawn.
 *
 * The POC implements only the methods HttpClient and friends actually need:
 * `call(Closure)` (Suspendable) plus a passthrough to the underlying scope's
 * cancellation helpers. Add more delegations here as services demand them.
 */
class DeferredScope implements Suspendable, Cancellable
{
    public bool $isCancelled {
        get => $this->resolve()->isCancelled;
    }

    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        return $this->resolve()->call($fn, $waitReason);
    }

    public function throwIfCancelled(): void
    {
        $this->resolve()->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->resolve()->cancellation();
    }

    private function resolve(): ExecutionScope
    {
        $current = CoroutineScopeRegistry::current();
        if (!$current instanceof ExecutionScope) {
            throw new RuntimeException(
                'DeferredScope: no ExecutionScope installed in this coroutine. '
                . 'Call from inside a $scope->execute(...) or $scope->concurrent(...) body.',
            );
        }
        return $current;
    }
}
