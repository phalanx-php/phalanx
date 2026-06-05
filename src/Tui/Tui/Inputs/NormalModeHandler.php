<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Inputs;

use Phalanx\Tui\Tui\Core\Focusable;

interface NormalModeHandler extends Focusable
{
    public function handleNormalKey(KeyEvent $event): bool;
}
