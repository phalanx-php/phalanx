<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Theatron\Tdom\Renderable;

interface StatefulComponent
{
    public function __invoke(StatefulContext $ctx): Renderable;
}
