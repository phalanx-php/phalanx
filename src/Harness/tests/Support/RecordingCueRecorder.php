<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Support;

use Phalanx\Agora\Harness\CueRecorder;
use Phalanx\Panoply\Cue;

final class RecordingCueRecorder implements CueRecorder
{
    /** @var list<array{cue: Cue, sessionId: string, turnId: ?string}> */
    private(set) array $recorded = [];

    public function record(Cue $cue, string $sessionId, ?string $turnId): void
    {
        $this->recorded[] = ['cue' => $cue, 'sessionId' => $sessionId, 'turnId' => $turnId];
    }
}
