<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Inputs\KeyEvent;

interface AcceptsInput
{
    public function handleInput(KeyEvent $event): bool;
}
