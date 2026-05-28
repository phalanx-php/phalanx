<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Phalanx\Theatron\Focus\Focusable;

interface NormalModeHandler extends Focusable
{
    public function handleNormalKey(KeyEvent $event): bool;
}
