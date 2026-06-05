<?php

declare(strict_types=1);

namespace Phalanx\Cancellation;

final class Halted extends Cancelled
{
    public function __construct(string $reason = 'halted')
    {
        parent::__construct($reason);
    }
}
