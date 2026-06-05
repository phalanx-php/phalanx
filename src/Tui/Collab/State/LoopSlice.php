<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\State;

use Phalanx\Tui\Collab\Lifecycle\LoopStage;

final class LoopSlice
{
    public function __construct(
        private(set) LoopStage $stage = LoopStage::Receive,
    ) {
    }

    public function advance(LoopStage $stage): self
    {
        return new self($stage);
    }
}
