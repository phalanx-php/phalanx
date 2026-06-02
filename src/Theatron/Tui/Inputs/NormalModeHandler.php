<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

use Phalanx\Theatron\Tui\Core\Focusable;

interface NormalModeHandler extends Focusable
{
    public function handleNormalKey(KeyEvent $event): bool;
}
