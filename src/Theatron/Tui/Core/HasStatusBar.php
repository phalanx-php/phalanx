<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Tdom\Renderable;

interface HasStatusBar
{
    public function statusBar(): Renderable;
}
