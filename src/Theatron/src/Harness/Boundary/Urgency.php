<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Harness\Boundary;

enum Urgency: int
{
    case Queue = 0;
    case Prioritize = 50;
    case Interrupt = 100;

    public function priority(): int
    {
        return $this->value;
    }
}
