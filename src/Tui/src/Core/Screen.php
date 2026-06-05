<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Tdom\Renderable;

interface Screen
{
    public function __invoke(ScreenContext $ctx): Renderable;
}
