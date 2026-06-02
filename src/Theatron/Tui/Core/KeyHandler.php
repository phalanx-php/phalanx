<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Inputs\KeyEvent;

interface KeyHandler
{
    public function __invoke(KeyEvent $event): bool;
}
