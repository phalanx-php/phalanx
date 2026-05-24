<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

interface RuntimeServiceId
{
    public function key(): string;

    public function value(): string;
}
