<?php

declare(strict_types=1);

namespace Phalanx\Agents\Stream;

use Phalanx\AiProviders\Cue;

interface CueEmitter
{
    public function emit(Cue $cue): void;
}
