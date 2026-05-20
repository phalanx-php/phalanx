<?php

declare(strict_types=1);

namespace Phalanx\Athena\Stream;

use Phalanx\Panoply\Cue;
use Phalanx\Styx\Channel;

final class ChannelCueEmitter implements CueEmitter
{
    public function __construct(
        private Channel $channel,
    ) {
    }

    public function emit(Cue $cue): void
    {
        $this->channel->emit($cue);
    }
}
