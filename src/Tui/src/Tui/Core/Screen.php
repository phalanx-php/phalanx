<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Core\ScreenContext;
use Phalanx\Tui\Tui\Tdom\Renderable;

interface Screen
{
    public function __invoke(ScreenContext $ctx): Renderable;
}
