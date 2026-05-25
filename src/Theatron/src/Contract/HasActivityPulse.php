<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Contract;

interface HasActivityPulse
{
    public function activityIsBusy(): bool;

    public function tickActivity(): void;
}
