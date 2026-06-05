<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Inputs\MouseEvent;

interface AcceptsMouse
{
    public function handleMouse(MouseEvent $event): bool;
}
