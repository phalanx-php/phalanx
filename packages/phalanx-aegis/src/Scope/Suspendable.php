<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;

interface Suspendable
{
    /**
     * Run the closure in the calling coroutine, racing against scope cancellation.
     * Translation of aegis's await(PromiseInterface): under HOOK_ALL the closure's
     * blocking I/O suspends transparently; cancellation is enforced by registering
     * a Coroutine::cancel listener on the scope's token for the duration of the call.
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    public function call(Closure $fn): mixed;
}
