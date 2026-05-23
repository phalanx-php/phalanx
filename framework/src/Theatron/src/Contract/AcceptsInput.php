<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Input\KeyEvent;

interface AcceptsInput
{
    public function handleInput(KeyEvent $event): bool;
}
