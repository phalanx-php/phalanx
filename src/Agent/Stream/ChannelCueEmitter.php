<?php

declare(strict_types=1);

namespace Phalanx\Agent\Stream;

use Phalanx\AiProviders\Cue;
use Phalanx\Stream\Channel;

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
