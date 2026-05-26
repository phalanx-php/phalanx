<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Panoply\Cue;

interface CueRecorder
{
    public function record(Cue $cue, string $sessionId, ?string $turnId): void;
}
