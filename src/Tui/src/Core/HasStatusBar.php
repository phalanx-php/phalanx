<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Tdom\Renderable;

interface HasStatusBar
{
    public function statusBar(): Renderable;
}
