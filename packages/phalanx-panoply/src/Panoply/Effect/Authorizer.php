<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Effect;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Grant;

/**
 * Contract for objects that evaluate an {@see Effect} against an optional
 * {@see Grant} and produce a {@see Decision}. Implementations must be
 * deterministic: the same (effect, grant) pair always produces the same
 * Decision across runs and processes.
 */
interface Authorizer
{
    /**
     * Evaluate whether the effect is authorized under the supplied grant.
     * Deterministic: same (effect, grant) → same Decision across runs.
     *
     * When `$grant` is null, implementations MUST return
     * `Decision::denied(...)` with at least one reason code indicating absence
     * of grant (the canonical rules-based authorizer uses `'no-grant'`; other
     * implementations may use their own).
     */
    public function evaluate(Effect $effect, ?Grant $grant = null): Decision;
}
