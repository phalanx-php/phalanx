<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Persistence;

use Phalanx\Agora\Harness\CueRecorder;
use Phalanx\Panoply\Cue;

final class SurrealCueRecorder implements CueRecorder
{
    public function __construct(
        private SurrealEventLog $events,
    ) {
    }

    public function record(Cue $cue, string $sessionId, ?string $turnId): void
    {
        $draft = HarnessEventDraft::fromCue($cue, $sessionId, $turnId);
        $this->events->append($draft);
    }
}
