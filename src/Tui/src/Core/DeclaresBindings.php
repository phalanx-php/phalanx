<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Tui\Inputs\Binding;

interface DeclaresBindings
{
    /** @return list<Binding> */
    public function bindings(): array;
}
