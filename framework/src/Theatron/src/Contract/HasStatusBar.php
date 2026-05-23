<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Tdom\Renderable;

interface HasStatusBar
{
    public function statusBar(): Renderable;
}
