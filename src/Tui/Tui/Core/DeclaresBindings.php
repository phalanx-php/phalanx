<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Core;

use Phalanx\Tui\Tui\Inputs\Binding;

interface DeclaresBindings
{
    /** @return list<Binding> */
    public function bindings(): array;
}
