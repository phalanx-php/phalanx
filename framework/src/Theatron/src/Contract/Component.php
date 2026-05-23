<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Tdom\Renderable;

interface Component
{
    public function __invoke(RenderContext $ctx): Renderable;
}
