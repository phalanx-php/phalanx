<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Mark\Mark;
use Phalanx\Panoply\Clock;
use Phalanx\Panoply\Clock\SystemClock;
use Phalanx\Panoply\Cue\Activity;
use Phalanx\Panoply\Cue\Artifact;
use Phalanx\Panoply\Cue\Effect;
use Phalanx\Panoply\Cue\Invocation;
use Phalanx\Panoply\Cue\Output;
use Phalanx\Panoply\Cue\Output\TokenDelta;

/**
 * The canonical cue stream. Consumers (Theatron, Delphi, host apps)
 * read cues off of it via `foreach`, the typed filter methods, and the
 * inherited {@see Series} combinators.
 *
 * Filtering methods on this class return `static` so chains stay typed
 * as `Stream`. Map / pluck / chunk / flatten / zip return the base
 * {@see Series} because the element type changes.
 *
 * @extends Series<Cue>
 */
class Stream extends Series
{
    /**
     * All token-output cues — both deltas and stops. Useful for
     * rendering streaming model output.
     */
    public function tokens(): static
    {
        return $this->ofKind(Output\TokenDelta::class, Output\TokenStop::class);
    }

    /**
     * Every effect-related cue (request, arguments delta, authorization,
     * pause, execution, failure).
     */
    public function effects(): static
    {
        return $this->ofKind(
            Effect\Requested::class,
            Effect\ArgumentsDelta::class,
            Effect\Authorized::class,
            Effect\Denied::class,
            Effect\Paused::class,
            Effect\Executed::class,
            Effect\Failed::class,
        );
    }

    /**
     * Every artifact-related cue (drafting, delta, finalized).
     */
    public function artifacts(): static
    {
        return $this->ofKind(
            Artifact\Drafting::class,
            Artifact\Delta::class,
            Artifact\Finalized::class,
        );
    }

    /**
     * Activity + Invocation lifecycle cues.
     */
    public function lifecycle(): static
    {
        return $this->ofKind(
            Activity\Started::class,
            Activity\Completed::class,
            Activity\Failed::class,
            Activity\Cancelled::class,
            Invocation\Started::class,
            Invocation\Completed::class,
            Invocation\Failed::class,
            Invocation\Cancelled::class,
        );
    }

    /**
     * Filter to cues matching any of the supplied class-strings via `instanceof`.
     *
     * @param class-string<Cue> ...$cueClasses
     */
    public function ofKind(string ...$cueClasses): static
    {
        if ($cueClasses === []) {
            return $this;
        }

        return $this->where(static fn(Cue $cue): bool => array_any(
            $cueClasses,
            static fn(string $class): bool => $cue instanceof $class,
        ));
    }

    /**
     * Merge adjacent {@see TokenDelta} cues that share the same channel and
     * arrive within `$window` of the *first unflushed delta* into a single
     * delta with concatenated text. All other cue types pass through unchanged.
     *
     * The window is measured from the moment the buffer is opened (when the
     * first un-buffered delta arrives), not from each individual delta — this
     * is a from-start window, not a sliding per-pair window. A delta that
     * arrives after the window has elapsed since the buffer opened flushes the
     * current buffer and opens a fresh one.
     *
     * Useful for reducing downstream render pressure: a 200 ms window turns
     * 50 sub-millisecond token events into a handful of coarser batches
     * without changing the assembled transcript.
     *
     * Identity policy: the merged delta keeps the first delta's id, sequence,
     * activityId, invocationId, agentId, at, and channel; only the text is
     * extended.
     *
     * Channel switching flushes the pending buffer immediately, even if the
     * window has not elapsed, so thinking and message tokens never bleed into
     * each other. Non-TokenDelta cues also flush the buffer first, preserving
     * the relative ordering of lifecycle and effect cues.
     */
    public function coalescing(Mark $window, ?Clock $clock = null): static
    {
        $clock ??= new SystemClock();
        $source = $this->source;
        $windowMicros = $window->toMicroseconds();

        return new static(static function () use ($source, $clock, $windowMicros): \Generator {
            $buffer = null;
            $bufferStartedAt = null;

            foreach ($source() as $cue) {
                if ($cue instanceof TokenDelta) {
                    if ($buffer === null) {
                        $buffer = $cue;
                        $bufferStartedAt = $clock->nowMicroseconds();
                        continue;
                    }

                    // Same channel + within window → merge into buffer
                    if (
                        $cue->channel === $buffer->channel
                        && ($clock->nowMicroseconds() - $bufferStartedAt) < $windowMicros
                    ) {
                        $buffer = self::mergeTokenDeltas($buffer, $cue);
                        continue;
                    }

                    // Different channel OR window elapsed → flush, start fresh
                    yield $buffer;
                    $buffer = $cue;
                    $bufferStartedAt = $clock->nowMicroseconds();
                    continue;
                }

                // Non-TokenDelta: flush any pending buffer first, then pass through
                if ($buffer !== null) {
                    yield $buffer;
                    $buffer = null;
                    $bufferStartedAt = null;
                }
                yield $cue;
            }

            // End of stream: flush trailing buffer
            if ($buffer !== null) {
                yield $buffer;
            }
        });
    }

    /**
     * Concatenate two adjacent TokenDelta cues. Preserves the first delta's
     * identity fields (id, sequence, activityId, invocationId, agentId, at,
     * channel); appends the second delta's text.
     *
     * Private to prevent external callers from bypassing the coalescing
     * semantics contract (same-channel, within-window).
     */
    private static function mergeTokenDeltas(TokenDelta $first, TokenDelta $second): TokenDelta
    {
        return new TokenDelta(
            id: $first->id,
            sequence: $first->sequence,
            activityId: $first->activityId,
            invocationId: $first->invocationId,
            agentId: $first->agentId,
            at: $first->at,
            text: $first->text . $second->text,
            channel: $first->channel,
        );
    }
}
