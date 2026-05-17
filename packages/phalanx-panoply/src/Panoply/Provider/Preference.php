<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

/**
 * Ordering preference an agent declares for provider selection. The
 * resolver honors `prefer()` first, then falls back through `fallback()`
 * declarations in order. `AgentChoice` leaves the decision to the agent
 * runtime's policy (e.g. capability-driven scoring).
 */
enum Preference: string
{
    case LocalFirst  = 'local-first';
    case Hosted      = 'hosted';
    case AgentChoice = 'agent-choice';
}
