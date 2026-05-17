<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Base class for every panoply cue. A cue announces a moment in an
 * agent's work — a token arrived, an effect was requested, an artifact
 * finalized, an activity completed. Streams of cues are the canonical
 * surface consumers (Theatron, Delphi, host apps) read.
 *
 * Every cue carries the six base fields plus a stable string `type`
 * identifier (e.g. `'cue.output.token_delta'`) — the `type` is what
 * goes into the hash, NOT the PHP class name, so renaming a class
 * doesn't break replay or audit keys.
 *
 * Concrete subclasses declare additional fields via promoted constructor
 * `private(set)` properties and override {@see self::payload()} to return
 * their type-specific fields. The `$type` hook and `payload()` method are
 * `final` on every leaf so a sub-subclass cannot silently divert hash
 * shape; the base's `toCanonical()` is `final` for the same reason.
 */
abstract class Cue implements Canonicalizable
{
    abstract public string $type { get; }

    public function __construct(
        private(set) string $id,
        private(set) int $sequence,
        private(set) string $activityId,
        private(set) ?string $invocationId,
        private(set) ?string $agentId,
        private(set) \DateTimeImmutable $at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    final public function toCanonical(): array
    {
        return [
            'type'          => $this->type,
            'id'            => $this->id,
            'sequence'      => $this->sequence,
            'activity_id'   => $this->activityId,
            'invocation_id' => $this->invocationId,
            'agent_id'      => $this->agentId,
            // Normalize to UTC and emit microsecond precision with a literal
            // 'Z' suffix; preserves microsecond determinism across hosts in
            // any timezone.
            'at'            => $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'payload'       => $this->payload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;
}
