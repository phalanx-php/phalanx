<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Theatron\Tdom\Renderable;

interface PureComponent
{
    public function __invoke(PureContext $ctx): Renderable;
}
