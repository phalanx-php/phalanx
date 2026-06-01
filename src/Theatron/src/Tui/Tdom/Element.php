<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Tdom;

interface Element extends Renderable
{
    public ElementType $type { get; }
}
