<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Theatron\Tui\Inputs\Binding;

interface DeclaresBindings
{
    /** @return list<Binding> */
    public function bindings(): array;
}
