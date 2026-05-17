<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Hazard\Scorer\Rules;

use Phalanx\Panoply\Effect;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Hazard;
use Phalanx\Panoply\Hazard\Scorer as ScorerContract;

/**
 * Canonical v0 rules-based {@see ScorerContract}. Deterministic:
 * the same Effect always produces the same Hazard across runs and
 * platforms.
 *
 * Canonical mapping for all 9 Effect\Kind cases:
 *
 * - FileRead, FileList, CodeSearch                               → Low
 * - WebFetch, FileWrite, MemoryWrite, KnowledgeWrite,
 *   ProviderCall                                                 → Medium
 * - ShellExec, Custom                                            → High
 *   (Custom carries unknown intent; treat as high to fail-safe)
 *
 * No constructor state — Scorer is a pure function over Effect.
 *
 * Final — subclassing would alter deterministic scoring that consumers
 * (Authorizer, audit, replay) depend on.
 */
final class Scorer implements ScorerContract
{
    public function score(Effect $effect): Hazard
    {
        return match ($effect->kind) {
            EffectKind::FileRead,
            EffectKind::FileList,
            EffectKind::CodeSearch       => Hazard::Low,
            EffectKind::WebFetch,
            EffectKind::FileWrite,
            EffectKind::MemoryWrite,
            EffectKind::KnowledgeWrite,
            EffectKind::ProviderCall     => Hazard::Medium,
            EffectKind::ShellExec,
            EffectKind::Custom           => Hazard::High,
        };
    }
}
