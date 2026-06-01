<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Inputs\KeySequenceState;

interface HasKeySequenceState
{
    public function keySequenceState(): KeySequenceState;

    public function updateKeySequence(KeySequenceState $state): void;
}
