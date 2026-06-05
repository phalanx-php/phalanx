<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Effect;

use Phalanx\AiProviders\Hash\Canonicalizable;

/**
 * Authorization decision produced by the Authorizer. Invalid field
 * combinations (e.g. a grant id on a denied decision) are unreachable
 * by construction — use the named factories rather than calling the
 * private constructor directly.
 *
 * `final` because subclassing would alter {@see self::toCanonical()} and
 * break Canonical hash stability.
 */
final class Decision implements Canonicalizable
{
    /** @var list<string> */
    private(set) array $reasonCodes;

    /**
     * @param list<string> $reasonCodes
     */
    private function __construct(
        private(set) Decision\Verdict $verdict,
        private(set) ?string $grantId,
        array $reasonCodes,
        private(set) ?string $pauseReason,
    ) {
        $this->reasonCodes = self::dedupReasonCodes($reasonCodes);
    }

    public static function granted(string $grantId): self
    {
        return new self(
            verdict: Decision\Verdict::Granted,
            grantId: $grantId,
            reasonCodes: [],
            pauseReason: null,
        );
    }

    public static function denied(string ...$reasonCodes): self
    {
        /** @var list<string> $codes */
        $codes = array_values($reasonCodes);

        return new self(
            verdict: Decision\Verdict::Denied,
            grantId: null,
            reasonCodes: $codes,
            pauseReason: null,
        );
    }

    public static function paused(string $reason): self
    {
        return new self(
            verdict: Decision\Verdict::Paused,
            grantId: null,
            reasonCodes: [],
            pauseReason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        $codes = $this->reasonCodes;
        sort($codes);

        return [
            'verdict' => $this->verdict->value,
            'grant_id' => $this->grantId,
            'reason_codes' => $codes,
            'pause_reason' => $this->pauseReason,
        ];
    }

    public function isGranted(): bool
    {
        return $this->verdict === Decision\Verdict::Granted;
    }

    public function isDenied(): bool
    {
        return $this->verdict === Decision\Verdict::Denied;
    }

    public function isPaused(): bool
    {
        return $this->verdict === Decision\Verdict::Paused;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function dedupReasonCodes(array $codes): array
    {
        $seen = [];
        $out = [];
        foreach ($codes as $code) {
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $code;
        }

        return $out;
    }
}
