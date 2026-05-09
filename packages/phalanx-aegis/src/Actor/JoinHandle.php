<?php

declare(strict_types=1);

namespace Phalanx\Actor;

use Throwable;

interface JoinHandle
{
    public function getState(): JoinState;

    public function getResult(): mixed;

    public function getError(): ?Throwable;

    public function wait(?float $timeout = null): mixed;
}
