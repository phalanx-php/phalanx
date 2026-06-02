<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Tdom\Renderable;

interface Component
{
    public function __invoke(RenderContext $ctx): Renderable;
}
