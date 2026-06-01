<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Inputs\KeyEvent;
use Phalanx\Theatron\Tui\Inputs\KeySequenceState;

interface HandlesKeySequences
{
    public function startsKeySequence(KeyEvent $event): bool;

    public function handleKeySequence(KeySequenceState $state, KeyEvent $event): bool;
}
