<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Input\KeyEvent;

interface KeyHandler
{
    public function __invoke(KeyEvent $event): bool;
}
