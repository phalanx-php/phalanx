<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Inputs\KeyEvent;
use Phalanx\Tui\Tui\Inputs\KeySequenceState;

interface HandlesKeySequences
{
    public function startsKeySequence(KeyEvent $event): bool;

    public function handleKeySequence(KeySequenceState $state, KeyEvent $event): bool;
}
