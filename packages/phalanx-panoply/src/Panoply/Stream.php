<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Cue\Activity;
use Phalanx\Panoply\Cue\Artifact;
use Phalanx\Panoply\Cue\Effect;
use Phalanx\Panoply\Cue\Invocation;
use Phalanx\Panoply\Cue\Output;

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
final class Stream extends Series
{
    /**
     * All token-output cues — both deltas and stops. Useful for
     * rendering streaming model output.
     */
    public function tokens(): self
    {
        return $this->ofKind(Output\TokenDelta::class, Output\TokenStop::class);
    }

    /**
     * Every effect-related cue (request, arguments delta, authorization,
     * pause, execution, failure).
     */
    public function effects(): self
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
    public function artifacts(): self
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
    public function lifecycle(): self
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
    public function ofKind(string ...$cueClasses): self
    {
        if ($cueClasses === []) {
            return $this;
        }

        return $this->where(static fn(Cue $cue): bool => array_any($cueClasses, fn($class) => $cue instanceof $class));
    }
}
