<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Input\MouseEvent;

interface AcceptsMouse
{
    public function handleMouse(MouseEvent $event): bool;
}
