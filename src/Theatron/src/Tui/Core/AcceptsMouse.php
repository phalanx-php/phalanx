<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Inputs\MouseEvent;

interface AcceptsMouse
{
    public function handleMouse(MouseEvent $event): bool;
}
