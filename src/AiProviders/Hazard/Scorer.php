<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Hazard;

use Phalanx\AiProviders\Effect;
use Phalanx\AiProviders\Hazard;

/**
 * Contract for objects that assign a {@see Hazard} rating to an
 * {@see Effect}. Implementations must be deterministic: the same Effect
 * always produces the same Hazard across runs and processes.
 */
interface Scorer
{
    /**
     * Score the effect's risk level.
     * Deterministic: same Effect → same Hazard across runs.
     */
    public function score(Effect $effect): Hazard;
}
