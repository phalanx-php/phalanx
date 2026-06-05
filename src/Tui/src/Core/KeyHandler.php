<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Inputs\KeyEvent;

interface KeyHandler
{
    public function __invoke(KeyEvent $event): bool;
}
