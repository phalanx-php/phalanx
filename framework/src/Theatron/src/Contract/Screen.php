<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Tdom\Renderable;

interface Screen
{
    public function __invoke(ScreenContext $ctx): Renderable;
}
