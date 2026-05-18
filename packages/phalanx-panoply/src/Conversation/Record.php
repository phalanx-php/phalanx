<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Base class for every normalized conversation record. A record announces
 * a moment in an agent's conversation history — a message exchanged, a tool
 * invoked, a file snapshotted, a permission decision made. Streams of records
 * are the canonical surface produced by {@see Parser} implementations when
 * reading a tool's home directory (Claude Code, Codex, Gemini CLI, etc.).
 *
 * The Record taxonomy mirrors Cue: every leaf is `final`, the `$type` hook
 * is sealed on every leaf via a `final` property hook, and `payload()` is
 * sealed on every leaf via `final`. The base's `toCanonical()` is `final`
 * here. Together these three seals guarantee canonical-hash determinism even
 * though the taxonomy is open for vendor-specific record kinds: no leaf can
 * silently divert hash shape through inheritance.
 *
 * The stable `$type->value` string — not the PHP class name — goes into
 * `toCanonical()`. Renaming a class never breaks replay keys or audit
 * fingerprints.
 *
 * The `$type` hook uses a backed enum (`RecordType`) rather than a plain
 * string, unlike `Cue`'s base — Records always carry a closed type set so
 * the enum form is preferable; Cue stays string-typed because cue subtypes
 * can be vendor-defined.
 *
 * Vendor subclasses (`MyCustomRecord extends Record`) must preserve the
 * single invariant that the `$type` hook returns a stable `RecordType` case.
 */
abstract class Record implements Canonicalizable
{
    abstract public RecordType $type { get; }

    public function __construct(
        private(set) string $id,
        private(set) ?int $sequence,
        private(set) \DateTimeImmutable $at,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    final public function toCanonical(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'sequence' => $this->sequence,
            // Normalize to UTC and emit microsecond precision with a literal
            // 'Z' suffix; preserves microsecond determinism across hosts in
            // any timezone.
            'at' => $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'payload' => $this->payload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;
}
