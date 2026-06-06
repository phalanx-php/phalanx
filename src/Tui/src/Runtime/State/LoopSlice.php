<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\State;

use Phalanx\Tui\Runtime\Lifecycle\LoopStage;

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
