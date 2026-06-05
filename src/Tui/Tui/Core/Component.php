<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Core\RenderContext;
use Phalanx\Tui\Tui\Tdom\Renderable;

interface Component
{
    public function __invoke(RenderContext $ctx): Renderable;
}
