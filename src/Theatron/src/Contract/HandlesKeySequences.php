<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\KeySequenceState;

interface HandlesKeySequences
{
    public function startsKeySequence(KeyEvent $event): bool;

    public function handleKeySequence(KeySequenceState $state, KeyEvent $event): bool;
}
