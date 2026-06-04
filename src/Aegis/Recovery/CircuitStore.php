<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Throwable;

interface CircuitStore
{
    public function beforeAttempt(Circuit $circuit): CircuitSnapshot;

    public function recordSuccess(Circuit $circuit): void;

    public function recordFailure(Circuit $circuit, Throwable $error): void;
}
