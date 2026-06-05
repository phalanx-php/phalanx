<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Inputs\KeySequenceState;

interface HasKeySequenceState
{
    public function keySequenceState(): KeySequenceState;

    public function updateKeySequence(KeySequenceState $state): void;
}
