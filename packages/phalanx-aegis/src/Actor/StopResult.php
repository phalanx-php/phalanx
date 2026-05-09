<?php

declare(strict_types=1);

namespace Phalanx\Actor;

interface StopResult
{
    public function getStatus(): StopStatus;

    public function getMessage(): ?string;

    public function wasGraceful(): bool;
}
