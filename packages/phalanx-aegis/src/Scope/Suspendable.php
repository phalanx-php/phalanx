<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Supervisor\WaitReason;

interface Suspendable
{
    /**
     * Run the closure in the calling coroutine, racing against scope cancellation.
     * Under HOOK_ALL the closure's blocking I/O suspends transparently;
     * cancellation is enforced by registering a Coroutine::cancel listener on
     * the scope's token for the duration of the call.
     *
     * Pass $waitReason so the supervisor records what the active TaskRun is
     * parked on. The diagnostic surface (task tree dump, leak reports) shows
     * the reason next to each suspended run. Service clients should pass a
     * concrete reason — WaitReason::http(...), WaitReason::postgres(...),
     * WaitReason::redis(...) — so users don't see opaque "call()".
     *
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed;
}
