<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Phalanx\Panoply\Cue;

interface CueEmitter
{
    public function emit(Cue $cue): void;
}
