<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

use DateTimeImmutable;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;

class ConversationTurnEvent
{
    public function __construct(
        private(set) string $id,
        private(set) DateTimeImmutable $at,
        private(set) ?Cue $cue = null,
        private(set) ?Channel $channel = null,
        private(set) string $text = '',
    ) {
    }

    public static function fromCue(Cue $cue): self
    {
        $channel = match (true) {
            $cue instanceof TokenDelta => $cue->channel,
            $cue instanceof TokenStop => $cue->channel,
            default => null,
        };

        return new self(
            id: $cue->id,
            at: $cue->at,
            cue: $cue,
            channel: $channel,
            text: $cue instanceof TokenDelta ? $cue->text : '',
        );
    }

    public static function token(string $id, DateTimeImmutable $at, string $text, Channel $channel): self
    {
        return new self(
            id: $id,
            at: $at,
            channel: $channel,
            text: $text,
        );
    }
}
