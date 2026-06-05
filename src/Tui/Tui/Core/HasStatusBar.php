<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Tdom\Renderable;

interface HasStatusBar
{
    public function statusBar(): Renderable;
}
