<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Contract for objects that can execute an {@see Invocation} against an
 * underlying AI provider. Returns the canonical cue stream — Activity\Started,
 * Provider\Resolved, Output\TokenDelta/Stop, Effect\Requested,
 * Activity\Completed/Failed, and so on. Implementations bind cancellation and
 * resource cleanup via the supplied {@see Runtime}.
 */
interface Provider
{
    /**
     * Run the invocation against the underlying provider.
     */
    public function perform(Invocation $invocation, Runtime $runtime): Stream;

    /**
     * Advertise the closed-set capabilities this provider supports
     * (reasoning, tool-use, vision, structured-output, etc.). Hosts use
     * this to gate Invocation::of(...) before perform() runs.
     */
    public function capabilities(): Capabilities;
}
