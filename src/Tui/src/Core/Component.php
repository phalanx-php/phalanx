<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Tdom\Renderable;

interface Component
{
    public function __invoke(RenderContext $ctx): Renderable;
}
