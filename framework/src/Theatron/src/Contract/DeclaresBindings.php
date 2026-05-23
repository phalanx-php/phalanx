<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

use Phalanx\Theatron\Binding\Binding;

interface DeclaresBindings
{
    /** @return list<Binding> */
    public function bindings(): array;
}
