<?php

declare(strict_types=1);

namespace Phalanx\Agents\Stream;

use Phalanx\AiProviders\Cue;

final class ArrayCueEmitter implements CueEmitter
{
    /** @var list<Cue> */
    private(set) array $cues = [];

    public function emit(Cue $cue): void
    {
        $this->cues[] = $cue;
    }

    /** @return list<Cue> */
    public function drain(): array
    {
        $drained = $this->cues;
        $this->cues = [];

        return $drained;
    }
}
