<?php

declare(strict_types=1);

namespace Phalanx\Cancellation;

use RuntimeException;

final class Cancelled extends RuntimeException
{
    public function __construct(string $reason = 'scope cancelled')
    {
        parent::__construct($reason);
    }
}
