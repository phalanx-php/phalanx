<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Effect\Authorizer\Rules;

use Phalanx\AiProviders\Effect;
use Phalanx\AiProviders\Effect\Authorizer as AuthorizerContract;
use Phalanx\AiProviders\Effect\Decision;
use Phalanx\AiProviders\Grant;

/**
 * Canonical v0 rules-based {@see AuthorizerContract}. Deterministic:
 * the same (effect, grant) pair always produces the same Decision across
 * runs and platforms.
 *
 * Decision logic (evaluated in order):
 * - grant absent                                  → denied('no-grant')
 * - grant does not permit the effect kind         → denied('effect-not-allowed')
 * - effect hazard exceeds the grant ceiling       → denied('hazard-exceeds-ceiling')
 * - grant is expired at the evaluation instant    → denied('grant-expired')
 * - otherwise                                     → granted($grant->id)
 *
 * Reason codes are stable strings. Adapter authors may match on them.
 *
 * The optional `$now` constructor parameter makes the authorizer fully
 * testable — inject a fixed instant for deterministic grant-expiry
 * verification. Production code constructs without an argument.
 *
 * Note: Decision::paused() is intentionally NOT emitted by this
 * implementation. Pause represents a human-in-the-loop approval gate
 * that a host wraps around the authorizer; v0 rules are auto-grant or
 * auto-deny only. Hosts that need approval gates compose this Authorizer
 * with their own pause-emitting decorator before forwarding the Decision
 * to the cue stream.
 *
 * Final — subclassing would alter the deterministic-rule contract pinned
 * by acceptance gate #11.
 */
final class Authorizer implements AuthorizerContract
{
    public function __construct(
        private(set) ?\DateTimeImmutable $now = null,
    ) {
    }

    public function evaluate(Effect $effect, ?Grant $grant = null): Decision
    {
        if ($grant === null) {
            return Decision::denied('no-grant');
        }

        if (!$grant->permits($effect->kind)) {
            return Decision::denied('effect-not-allowed');
        }

        if ($effect->hazard !== null && $effect->hazard->exceeds($grant->hazardCeiling)) {
            return Decision::denied('hazard-exceeds-ceiling');
        }

        $now = $this->now ?? new \DateTimeImmutable();

        if ($grant->isExpired($now)) {
            return Decision::denied('grant-expired');
        }

        return Decision::granted($grant->id);
    }
}
