<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Effect;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Terminal result of a completed activity or effect execution. Invalid
 * field combinations (e.g. error fields on a succeeded outcome) are
 * unreachable by construction — use the named factories rather than
 * calling the private constructor directly.
 *
 * `final` because subclassing would alter {@see self::toCanonical()} and
 * break Canonical hash stability.
 *
 * Outcome is the host-internal value object an Executor produces when it
 * finishes running an Effect. The corresponding wire-stable signal is
 * carried by `Cue\Effect\Executed` (success) or `Cue\Effect\Failed`
 * (failure), which reference the effect by string id only — Outcome itself
 * is not embedded in the cue stream so the cue payload can remain stable
 * across replay/audit forever, independent of host-side value-object shape.
 */
final class Outcome implements Canonicalizable
{
    private function __construct(
        private(set) Outcome\State $state,
        private(set) ?string $valueDigest,
        private(set) ?string $errorClass,
        private(set) ?string $errorMessage,
        private(set) int $durationMs,
    ) {
    }

    public static function succeeded(?string $valueDigest, int $durationMs): self
    {
        return new self(
            state: Outcome\State::Succeeded,
            valueDigest: $valueDigest,
            errorClass: null,
            errorMessage: null,
            durationMs: $durationMs,
        );
    }

    public static function failed(string $errorClass, string $errorMessage, int $durationMs): self
    {
        return new self(
            state: Outcome\State::Failed,
            valueDigest: null,
            errorClass: $errorClass,
            errorMessage: $errorMessage,
            durationMs: $durationMs,
        );
    }

    public static function cancelled(int $durationMs): self
    {
        return new self(
            state: Outcome\State::Cancelled,
            valueDigest: null,
            errorClass: null,
            errorMessage: null,
            durationMs: $durationMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'state'         => $this->state->value,
            'value_digest'  => $this->valueDigest,
            'error_class'   => $this->errorClass,
            'error_message' => $this->errorMessage,
            'duration_ms'   => $this->durationMs,
        ];
    }

    public function isSucceeded(): bool
    {
        return $this->state === Outcome\State::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->state === Outcome\State::Failed;
    }

    public function isCancelled(): bool
    {
        return $this->state === Outcome\State::Cancelled;
    }
}
