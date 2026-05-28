<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Phalanx\Theatron\Focus\Focusable;

interface InputTarget extends Focusable
{
    public function handleInput(InputEvent $event): bool;
}
