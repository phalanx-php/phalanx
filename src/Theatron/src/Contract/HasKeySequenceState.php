<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Input\KeySequenceState;

interface HasKeySequenceState
{
    public function keySequenceState(): KeySequenceState;

    public function updateKeySequence(KeySequenceState $state): void;
}
