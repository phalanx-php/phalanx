<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Core\ScreenContext;
use Phalanx\Theatron\Tui\Tdom\Renderable;

interface Screen
{
    public function __invoke(ScreenContext $ctx): Renderable;
}
