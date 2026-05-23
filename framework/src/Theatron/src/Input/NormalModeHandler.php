<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Input;

use Phalanx\Theatron\Contract\Focusable;

interface NormalModeHandler extends Focusable
{
    public function handleNormalKey(KeyEvent $event): bool;
}
